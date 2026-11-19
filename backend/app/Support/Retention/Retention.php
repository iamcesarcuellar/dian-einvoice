<?php

namespace App\Support\Retention;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;

/**
 * Runs every tagged Pruner with the cutoff of its retention: now minus its days. A retention of 0
 * days or less turns that pruner off.
 */
final class Retention
{
    /** Batches of one kind per run, so one run never holds the relay for long. */
    public const int MAX_BATCHES = 20;

    /** Rows per batch. */
    public const int BATCH = 500;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array<string, int> How many records each pruner deleted.
     */
    public function prune(): array
    {
        $deleted = [];

        foreach ($this->container->tagged(Pruner::class) as $pruner) {
            /** @var Pruner $pruner */
            $days = config()->integer('retention.days.'.$pruner->name(), 0);

            if ($days <= 0) {
                continue;
            }

            $deleted[$pruner->name()] = $pruner->prune(Carbon::now()->subDays($days));
        }

        return $deleted;
    }

    /**
     * Runs $batch (which deletes up to BATCH rows and says how many) until a batch comes back short.
     *
     * @param  callable(): int  $batch
     */
    public static function inBatches(callable $batch): int
    {
        $total = 0;

        for ($round = 0; $round < self::MAX_BATCHES; $round++) {
            $count = $batch();
            $total += $count;

            if ($count < self::BATCH) {
                break;
            }
        }

        return $total;
    }
}
