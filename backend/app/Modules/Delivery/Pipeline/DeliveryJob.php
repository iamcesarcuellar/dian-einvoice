<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
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
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * One step of the customer delivery, as a queued job built from an outbox event, with the same rules
 * as the electronic document chain (see ChainStep): it acts on the company of the document as the
 * system, and its process() method locks the document and does nothing unless the document is
 * still pending at this step, in the run the event names. A repeated event, or two workers with the
 * same event, do no work twice. process() is called through the container.
 *
 * Expected failures (building, drawing, packaging or sending) end the delivery as failed inside the
 * step. Unexpected errors let the queue retry the job; when it gives up, failed() marks the delivery
 * failed so none is left pending. Either way the message ends in the dead-letter queue (failed_jobs
 * locally); moved back once the cause is fixed, the step starts again as Send again does, for a delivery
 * that still failed there in that run (docs/runbooks/dead-letters.md).
 */
abstract class DeliveryJob implements ConsumesOutboxEvents
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
    ) {}

    public static function fromOutboxEvent(OutboxEvent $event): static
    {
        return new static(
            companyId: $event->company_id,
            documentId: $event->payloadString('document_id') ?? $event->aggregate_id,
            run: $event->payloadInt('delivery_run') ?? 1,
            attempt: $event->payloadInt('attempt') ?? 0,
        );
    }

    abstract public function step(): DeliveryStep;

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
     * The message of a step of the delivery that gave up came back: an operator moved its dead letter back to the
     * queue. The delivery still failed at this step in this run, so it starts again from here, to the same
     * recipient, as Send again does; any other message does nothing.
     */
    private function resumesItsDeadLetter(): bool
    {
        // A read without a lock first: only a delivery that gave up here pays for the lock.
        if (! $this->gaveUpHere(Document::query()->find($this->documentId))) {
            return false;
        }

        // Checked again and done under the lock of the document, so a Send again of a user meanwhile wins.
        $resumed = DB::transaction(function (): ?Document {
            if (! $this->gaveUpHere(app(DeliveryPipeline::class)->lock($this->documentId))) {
                return null;
            }

            try {
                return app(DeliveryActions::class)->sendAgain($this->documentId, null);
            } catch (ValidationException|HttpExceptionInterface) {
                // The delivery cannot start again by itself (a customer without an email): the message does nothing.
                return null;
            }
        }, 3);

        if ($resumed === null) {
            return false;
        }

        Telemetry::record(
            LogEvent::ChainResumed,
            sprintf('The %s step of the delivery came back from the dead-letter queue and starts again in run %d.', $this->step()->value, $resumed->delivery_run),
            outcome: 'retried',
            documentId: $this->documentId,
            detail: ['job' => class_basename(static::class)],
        );

        return true;
    }

    /** Whether the delivery of the document failed at this step in the run of this message. */
    private function gaveUpHere(?Document $document): bool
    {
        return $document !== null && $document->delivery_status === DeliveryStatus::Failed && $document->delivery_step === $this->step() && $document->delivery_run === $this->run;
    }

    /** The queue gave up on the job: the delivery ends as failed at this step, if it is still there. */
    public function failed(?Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $company->documentLanguage()->run(fn () => app(CurrentCompany::class)->runAs($company, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $pipeline = app(DeliveryPipeline::class);
                $document = $pipeline->lock($this->documentId);

                if ($document === null || ! $this->expects($document)) {
                    return;
                }

                $pipeline->fail($document, $this->step(), __('delivery::chain.unexpected', ['error' => $exception?->getMessage() ?? __('delivery::chain.job_failed')]), $document->delivery_attempt);
            });
        }));
    }

    /** Whether the document still waits for this step, in the run of the event. */
    protected function expects(Document $document): bool
    {
        return $document->delivery_status === DeliveryStatus::Pending
            && $document->delivery_step === $this->step()
            && $document->delivery_run === $this->run;
    }

    /**
     * Sends the job to the failed jobs table, the dead letter queue, after the delivery ended as
     * failed. A step run in line (the demo seeder) has no queue job, so nothing happens then.
     */
    protected function deadLetter(Throwable $reason): void
    {
        if ($this->job !== null) {
            $this->fail($reason);
        }
    }
}
