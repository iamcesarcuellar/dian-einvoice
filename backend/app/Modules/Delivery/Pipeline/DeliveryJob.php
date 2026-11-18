<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
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
 * One step of the customer delivery, as a queued job built from an outbox event, with the same rules
 * as the electronic document chain (see ChainStep): it acts on the company of the document as the
 * system, and its process() method locks the document and does nothing unless the document is
 * still pending at this step, in the run the event names. A repeated event, or two workers with the
 * same event, do no work twice. process() is called through the container.
 *
 * Expected failures (building, drawing, packaging or sending) end the delivery as failed inside the
 * step. Unexpected errors let the queue retry the job; when it gives up, failed() marks the delivery
 * failed so none is left pending.
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
        $payload = $event->payload;

        return new static(
            companyId: $event->company_id,
            documentId: (string) ($payload['document_id'] ?? $event->aggregate_id),
            run: (int) ($payload['delivery_run'] ?? 1),
            attempt: (int) ($payload['attempt'] ?? 0),
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
        // A company deleted meanwhile, such as the temporary one of make stress or make chaos.
        $company = Company::query()->find($this->companyId);

        if ($company !== null) {
            $currentCompany->runAs($company, fn () => app()->call([$this, 'process']));
        }
    }

    /** The queue gave up on the job: the delivery ends as failed at this step, if it is still there. */
    public function failed(?Throwable $exception): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(CurrentCompany::class)->runAs($company, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $pipeline = app(DeliveryPipeline::class);
                $document = $pipeline->lock($this->documentId);

                if ($document === null || ! $this->expects($document)) {
                    return;
                }

                $pipeline->fail($document, $this->step(), 'Unexpected error: '.($exception?->getMessage() ?? 'the job failed.'), $document->delivery_attempt);
            });
        });
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
