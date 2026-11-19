<?php

namespace App\Support\Retention;

use App\Support\Outbox\Sweeper;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pruning as one more sweeper of the outbox relay: at most once every PRUNE_EVERY_MINUTES, for every
 * relay together (an entry in the shared cache decides which one runs it).
 */
final class ScheduledPruning implements Sweeper
{
    public function __construct(
        private readonly Retention $retention,
        private readonly Cache $cache,
    ) {}

    public function sweep(): int
    {
        $minutes = max(1, config()->integer('retention.every_minutes', 60));

        if (! $this->cache->add('retention:pruned', true, $minutes * 60)) {
            return 0;
        }

        $deleted = array_filter($this->retention->prune());

        if ($deleted !== []) {
            Log::info('Pruned old operational records.', $deleted);
        }

        // Pruning is housekeeping, not a repair: it never counts as something restarted.
        return 0;
    }
}
