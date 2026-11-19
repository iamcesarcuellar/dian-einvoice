<?php

namespace App\Support\Outbox;

use App\Support\Retention\Pruner;
use App\Support\Retention\Retention;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Outbox events published to the queue before the cutoff; unpublished ones are never touched. */
final class PruneOutboxEvents implements Pruner
{
    public function name(): string
    {
        return 'outbox_events';
    }

    public function prune(CarbonInterface $before): int
    {
        return Retention::inBatches(fn (): int => DB::table('outbox_events')
            ->whereNotNull('published_at')
            ->where('published_at', '<', $before)
            ->orderBy('id')
            ->limit(Retention::BATCH)
            ->delete());
    }
}
