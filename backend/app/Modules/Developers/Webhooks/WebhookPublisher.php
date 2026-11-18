<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Billing\Http\Resources\DocumentResource;
use App\Modules\Billing\Models\Document;
use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Developers\Models\WebhookEvent;
use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Support\Outbox\Outbox;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Turns an outbox event into a public webhook event of the current company and queues one delivery
 * per active subscription that listens to it. The body is built once and kept byte for byte:
 * id, type, created_at, company_id and data.object, the same resource the API returns for that
 * document or payment, as it is when the event is published. Repeating the same outbox event adds
 * nothing: the event is unique by its source and the delivery by event and subscription.
 */
final class WebhookPublisher
{
    /** Payload delivery_id, run and attempt: the attempt to make; retries carry available_at. */
    public const string DELIVERY_DUE = 'webhook.delivery_due';

    public function __construct(
        private readonly Outbox $outbox,
        private readonly CurrentCompany $currentCompany,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  The payload of the outbox event.
     */
    public function publish(string $sourceEventId, string $outboxType, string $aggregateId, array $payload, DateTimeInterface $occurredAt): ?WebhookEvent
    {
        [$type, $object] = $this->resolve($outboxType, $aggregateId, $payload);

        if ($type === null) {
            return null;
        }

        $subscriptions = WebhookSubscription::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookSubscription $subscription): bool => $subscription->listensTo($type));

        if ($subscriptions->isEmpty()) {
            return null;
        }

        $id = Str::lower((string) Str::ulid());
        $body = $this->body($id, $type, $occurredAt, $object);

        return DB::transaction(function () use ($id, $type, $sourceEventId, $body, $occurredAt, $subscriptions): WebhookEvent {
            DB::table('webhook_events')->insertOrIgnore([
                'id' => $id,
                'company_id' => $this->currentCompany->id(),
                'type' => $type,
                'source_event_id' => $sourceEventId,
                'payload' => $body,
                'created_at' => Carbon::instance($occurredAt),
            ]);

            $event = WebhookEvent::query()->where('source_event_id', $sourceEventId)->firstOrFail();

            foreach ($subscriptions as $subscription) {
                $this->queue($event, $subscription);
            }

            return $event;
        }, 3);
    }

    /**
     * A test event for one subscription, whatever events it lists.
     */
    public function test(WebhookSubscription $subscription): WebhookDelivery
    {
        return DB::transaction(function () use ($subscription): WebhookDelivery {
            $id = Str::lower((string) Str::ulid());
            $now = Carbon::now();

            $event = WebhookEvent::create([
                'id' => $id,
                'type' => WebhookEventTypes::TEST,
                'payload' => $this->body($id, WebhookEventTypes::TEST, $now, [
                    'object' => 'webhook_test',
                    'subscription_id' => $subscription->getKey(),
                    'message' => 'This is a test event sent from the web app. It is signed like any other event.',
                ]),
                'created_at' => $now,
            ]);

            return $this->queue($event, $subscription) ?? throw new LogicException('A new test event always gets its delivery.');
        });
    }

    /**
     * Writes the delivery of an event to a subscription and the event of its first attempt, unless
     * the delivery exists already.
     */
    public function queue(WebhookEvent $event, WebhookSubscription $subscription): ?WebhookDelivery
    {
        $id = Str::lower((string) Str::ulid());
        $now = Carbon::now();

        $inserted = DB::table('webhook_deliveries')->insertOrIgnore([
            'id' => $id,
            'company_id' => $event->company_id,
            'subscription_id' => $subscription->getKey(),
            'event_id' => $event->getKey(),
            'event_type' => $event->type,
            'status' => WebhookDeliveryStatus::Pending->value,
            'run' => 1,
            'attempts' => 0,
            'run_attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        $this->outbox->record($event->company_id, 'webhook_delivery', $id, self::DELIVERY_DUE, ['delivery_id' => $id, 'run' => 1, 'attempt' => 1]);

        return WebhookDelivery::query()->findOrFail($id);
    }

    /**
     * The public type and data.object of an outbox event, or [null, null] when it has none.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string|null, 1: array<string, mixed>|null}
     */
    private function resolve(string $outboxType, string $aggregateId, array $payload): array
    {
        if (WebhookEventTypes::isPaymentEvent($outboxType)) {
            $payment = Payment::query()->with(Payment::DETAILS)->find((string) ($payload['payment_id'] ?? $aggregateId));

            return $payment === null
                ? [null, null]
                : [WebhookEventTypes::publicType($outboxType), PaymentResource::make($payment)->resolve()];
        }

        $document = Document::query()->with(Document::DETAILS)->find((string) ($payload['document_id'] ?? $aggregateId));

        if ($document === null) {
            return [null, null];
        }

        $type = WebhookEventTypes::publicType($outboxType, $document->type);

        return $type === null ? [null, null] : [$type, DocumentResource::make($document)->resolve()];
    }

    /**
     * @param  array<string, mixed>|null  $object
     */
    private function body(string $id, string $type, DateTimeInterface $occurredAt, ?array $object): string
    {
        return json_encode([
            'id' => WebhookEvent::ID_PREFIX.$id,
            'type' => $type,
            'created_at' => Carbon::instance($occurredAt)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'company_id' => $this->currentCompany->id(),
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
