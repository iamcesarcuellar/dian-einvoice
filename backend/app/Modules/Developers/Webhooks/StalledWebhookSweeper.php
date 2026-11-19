<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Modules\Developers\Models\WebhookAttempt;
use App\Modules\Developers\Models\WebhookDelivery;
use App\Modules\Identity\Tenancy\CompanyScope;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\Sweeper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Finds webhook deliveries still pending that stopped moving (90 seconds without progress and no
 * event of theirs waiting in the outbox or published in the last 90 seconds, such as a job lost
 * with the queue) and records the event of their
 * attempt again: the next one, or the one that never finished. Attempts are claimed, so a
 * duplicate is harmless.
 */
final class StalledWebhookSweeper implements Sweeper
{
    public const int STALLED_SECONDS = 90;

    private const int LIMIT = 100;

    public function __construct(private readonly Outbox $outbox) {}

    public function sweep(): int
    {
        $threshold = Carbon::now()->subSeconds(self::STALLED_SECONDS);

        $deliveries = WebhookDelivery::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('status', WebhookDeliveryStatus::Pending)
            ->where('updated_at', '<', $threshold)
            ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<', $threshold))
            // An event still waiting, or published less than 90 seconds ago (its job may be queued),
            // means it is moving. Demo deliveries carry the moved clock of the seeder, so their own
            // timestamps alone cannot tell.
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->from('outbox_events')
                ->whereColumn('outbox_events.aggregate_id', 'webhook_deliveries.id')
                ->where(fn (QueryBuilder $event) => $event->whereNull('outbox_events.published_at')->orWhere('outbox_events.published_at', '>', $threshold)))
            ->limit(self::LIMIT)
            ->get();

        foreach ($deliveries as $delivery) {
            $unfinished = WebhookAttempt::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('delivery_id', $delivery->getKey())
                ->where('run', $delivery->run)
                ->whereNull('finished_at')
                ->exists();

            $this->outbox->record($delivery->company_id, 'webhook_delivery', $delivery->id, WebhookPublisher::DELIVERY_DUE, [
                'delivery_id' => $delivery->getKey(),
                'run' => $delivery->run,
                'attempt' => $unfinished ? $delivery->attempts : $delivery->attempts + 1,
                'swept' => true,
            ]);

            // Not swept again before it had a chance to move.
            $delivery->touch();
        }

        return $deliveries->count();
    }
}
