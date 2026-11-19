<?php

namespace App\Modules\Developers\Retention;

use App\Modules\Developers\Enums\WebhookDeliveryStatus;
use App\Support\Retention\Pruner;
use App\Support\Retention\Retention;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Webhook deliveries that ended (succeeded or failed) before the cutoff, with their attempts (the
 * foreign key cascades), then the events no delivery points to any more. Pending deliveries stay.
 */
final class PruneWebhookDeliveries implements Pruner
{
    public function name(): string
    {
        return 'webhook_deliveries';
    }

    public function prune(CarbonInterface $before): int
    {
        $deliveries = Retention::inBatches(fn (): int => DB::table('webhook_deliveries')
            ->where('status', '!=', WebhookDeliveryStatus::Pending->value)
            ->where('updated_at', '<', $before)
            ->orderBy('id')
            ->limit(Retention::BATCH)
            ->delete());

        Retention::inBatches(fn (): int => DB::table('webhook_events')
            ->where('created_at', '<', $before)
            ->whereNotExists(fn (Builder $query) => $query->select(DB::raw(1))->from('webhook_deliveries')->whereColumn('webhook_deliveries.event_id', 'webhook_events.id'))
            ->orderBy('id')
            ->limit(Retention::BATCH)
            ->delete());

        return $deliveries;
    }
}
