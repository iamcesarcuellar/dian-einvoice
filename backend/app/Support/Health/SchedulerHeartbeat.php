<?php

namespace App\Support\Health;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * A scheduled task of the scheduler function: notes in the shared cache when the scheduler last ran, so
 * make cloud-check (and the alarms of the observability stack) can tell that it runs.
 */
final class SchedulerHeartbeat
{
    public const string KEY = 'scheduler:last-run';

    public function __invoke(): void
    {
        Cache::forever(self::KEY, Carbon::now()->getTimestamp());
    }

    /** When the scheduler last ran, as a Unix time, or null when it never did. */
    public static function lastRun(): ?int
    {
        // Cache stores give numbers back as text.
        $time = Cache::get(self::KEY);

        return is_numeric($time) ? (int) $time : null;
    }
}
