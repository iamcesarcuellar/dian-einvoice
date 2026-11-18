<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Outbox\ConsumesOutboxEvents;
use App\Support\Outbox\OutboxEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/**
 * Publishes one outbox event (invoice.issued, electronic_document.accepted, payment.recorded...) as
 * a public webhook event of its company, with one delivery per subscription that listens to it.
 * Repeating it is harmless: the event is unique by its outbox event.
 */
final class PublishWebhookEvent implements ConsumesOutboxEvents
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public int $timeout = 35;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $outboxEventId,
        public readonly string $companyId,
        public readonly string $type,
        public readonly string $aggregateId,
        public readonly array $payload,
        public readonly string $occurredAt,
    ) {}

    public static function fromOutboxEvent(OutboxEvent $event): self
    {
        return new self(
            outboxEventId: $event->getKey(),
            companyId: $event->company_id,
            type: $event->type,
            aggregateId: $event->aggregate_id,
            payload: $event->payload ?? [],
            occurredAt: ($event->created_at ?? Carbon::now())->toIso8601String(),
        );
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [1, 2, 5, 10, 30];
    }

    public function handle(CurrentCompany $currentCompany, WebhookPublisher $publisher): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $currentCompany->runAs($company, fn () => $publisher->publish(
            $this->outboxEventId,
            $this->type,
            $this->aggregateId,
            $this->payload,
            Carbon::parse($this->occurredAt),
        ));
    }
}
