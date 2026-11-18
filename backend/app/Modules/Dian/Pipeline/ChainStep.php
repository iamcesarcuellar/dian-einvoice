<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Models\Document;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Outbox\ConsumesOutboxEvents;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
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
 * gives up, failed() marks the document failed so no chain is left stuck.
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
        $payload = $event->payload;

        return new static(
            companyId: $event->company_id,
            documentId: (string) ($payload['document_id'] ?? $event->aggregate_id),
            run: (int) ($payload['run'] ?? 1),
            attempt: (int) ($payload['attempt'] ?? 0),
            transmissionId: isset($payload['transmission_id']) ? (string) $payload['transmission_id'] : null,
        );
    }

    /** The step, as recorded when the chain fails here: generate, sign or transmit. */
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
        // A company deleted meanwhile, such as the temporary one of make stress or make chaos.
        $company = Company::query()->find($this->companyId);

        if ($company !== null) {
            $currentCompany->runAs($company, fn () => app()->call([$this, 'process']));
        }
    }

    /** The queue gave up on the job: the chain ends as failed at this step, if it is still there. */
    public function failed(?Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(CurrentCompany::class)->runAs($company, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $pipeline = app(ElectronicDocumentPipeline::class);
                $document = $pipeline->lock($this->documentId);

                if ($document === null || $document->electronic_run !== $this->run || ! $this->expects($document)) {
                    return;
                }

                $pipeline->fail($document, $this->step(), 'Unexpected error: '.($exception?->getMessage() ?? 'the job failed.'), $document->electronic_attempt);
            });
        });
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
