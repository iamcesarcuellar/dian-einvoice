<?php

namespace App\Support\Retention;

use Carbon\CarbonInterface;

/**
 * Deletes the operational records of one kind that are older than their retention (config/retention.php),
 * across every company, a batch at a time. Modules tag their pruners with Pruner::class.
 */
interface Pruner
{
    /** The key of its retention in config/retention.php (days), also its name in the output. */
    public function name(): string;

    /** Deletes what finished before $before; returns how many records went. */
    public function prune(CarbonInterface $before): int;
}
