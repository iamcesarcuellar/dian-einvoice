<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;
use App\Support\Outbox\ConsumesOutboxEvents;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * One step of the electronic document chain, as a queued job built from an outbox event. It acts
 * on the company of the document as the system, and its process() method locks the document,
 * checks the state, run and attempt the event expects and returns without doing anything when
 * they no longer match: a repeated event, or two workers with the same event, do no work twice.
 * process() is called through the container, so it can type-hint what it needs.
 *
 * Expected failures (a generation, signing or transmission error) end the chain as failed inside
 * the step. Unexpected errors (the database, the storage) let the queue retry the job; when it
 * gives up, failed() marks the document failed so no chain is left stuck. Either way the message of
 * the step ends in the dead-letter queue (failed_jobs locally); when an operator moves it back (the
 * native redrive of SQS, or queue:retry locally) once the cause is fixed, the step starts again as
 * Retry now does, for a document that still failed there in that run (docs/runbooks/dead-letters.md).
 */
abstract class ChainStep implements ConsumesOutboxEvents
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Attempts of the job for unexpected errors, a worker that died included. */
    public int $tries = 10;

    /** Seconds before the worker stops the job; below REDIS_QUEUE_RETRY_AFTER. */
    public int $timeout = 35;

    final public function __construct(
        public readonly string $companyId,
        public readonly string $documentId,
        public readonly int $run,
        public readonly int $attempt = 0,
        public readonly ?string $transmissionId = null,
    ) {}

    public static function fromOutboxEvent(OutboxEvent $event): static
    {
        return new static(
            companyId: $event->company_id,
            documentId: $event->payloadString('document_id') ?? $event->aggregate_id,
            run: $event->payloadInt('run') ?? 1,
            attempt: $event->payloadInt('attempt') ?? 0,
            transmissionId: $event->payloadString('transmission_id'),
        );
    }

    /**
     * The step, as recorded when the chain fails here: generate, sign or transmit.
     *
     * @return 'generate'|'sign'|'transmit'
     */
    abstract public function step(): string;

    /** Whether the document still waits for this step, so a failure here belongs to it. */
    abstract protected function expects(Document $document): bool;

    /**
     * Seconds between the attempts of the job after unexpected errors.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 2, 5, 10, 30];
    }

    final public function handle(CurrentCompany $currentCompany): void
    {
        // Each step declares its own process(), with what it needs.
        $process = [$this, 'process'];

        if (! is_callable($process)) {
            throw new LogicException(static::class.' has no process() method.');
        }

        // Every log line of the step names its document (docs/observability.md).
        Context::add('document_id', $this->documentId);

        // A company deleted meanwhile, such as the temporary one of make stress or make chaos.
        $company = Company::query()->find($this->companyId);

        // In the language of the company's documents: the texts the step stores for the timeline, its PDF and its email.
        if ($company !== null) {
            $company->documentLanguage()->run(fn () => $currentCompany->runAs($company, fn () => $this->resumesItsDeadLetter() || app()->call($process)));
        }
    }

    /**
     * The message of a step that gave up came back: an operator moved its dead letter back to the queue. The
     * document still failed at this step in this run, at this attempt or the one before it (a transmission whose
     * job kept throwing before its claim), so the step starts again as Retry now does: a new run from here. Any
     * other message of a failed document, an older attempt or a run already retried, does nothing.
     */
    private function resumesItsDeadLetter(): bool
    {
        // A read without a lock first: only a document that gave up here pays for the lock.
        if (! $this->gaveUpHere(Document::query()->find($this->documentId))) {
            return false;
        }

        // Checked again and done under the lock of the document, so a Retry now of a user meanwhile wins.
        $resumed = DB::transaction(fn (): ?Document => $this->gaveUpHere(app(ElectronicDocumentPipeline::class)->lock($this->documentId))
            ? app(ElectronicDocumentActions::class)->retryNow($this->documentId)
            : null, 3);

        if ($resumed === null) {
            return false;
        }

        Telemetry::record(
            LogEvent::ChainResumed,
            sprintf('The %s step of the chain came back from the dead-letter queue and starts again in run %d.', $this->step(), $resumed->electronic_run),
            outcome: 'retried',
            documentId: $this->documentId,
            detail: ['job' => class_basename(static::class), 'attempt' => $this->attempt === 0 ? null : $this->attempt],
        );

        return true;
    }

    /** Whether the document failed at this step in the run of this message, at its attempt or the one before it. */
    private function gaveUpHere(?Document $document): bool
    {
        return $document !== null
            && $document->electronic_status === ElectronicStatus::Failed
            && $document->electronic_failed_step === $this->step()
            && $document->electronic_run === $this->run
            && ($this->step() !== 'transmit' || $this->attempt >= $document->electronic_attempt);
    }

    /** The queue gave up on the job: the chain ends as failed at this step, if it is still there. */
    public function failed(?Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $company->documentLanguage()->run(fn () => app(CurrentCompany::class)->runAs($company, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $pipeline = app(ElectronicDocumentPipeline::class);
                $document = $pipeline->lock($this->documentId);

                if ($document === null || $document->electronic_run !== $this->run || ! $this->expects($document)) {
                    return;
                }

                $pipeline->fail($document, $this->step(), __('dian::chain.unexpected', ['error' => $exception?->getMessage() ?? __('dian::chain.job_failed')]), $document->electronic_attempt);
            });
        }));
    }

    /**
     * Sends the job to the failed jobs table, the dead letter queue, after the chain ended as
     * failed. A step run in line (the demo seeder) has no queue job, so nothing happens then.
     */
    protected function deadLetter(Throwable $reason): void
    {
        if ($this->job !== null) {
            $this->fail($reason);
        }
    }
}
