<?php

namespace App\Support\Outbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * When the safety net of the outbox last ran (the relay function, once a minute), noted in the shared cache by
 * every run, so php artisan outbox:status tells an operator whether it runs (docs/runbooks/outbox-relay.md).
 */
final class RelayHeartbeat
{
    public const string KEY = 'outbox:relay:last-run';

    public static function beat(): void
    {
        Cache::forever(self::KEY, Carbon::now()->getTimestamp());
    }

    /** When the relay last ran, as a Unix time, or null when it never did. */
    public static function lastRun(): ?int
    {
        // Cache stores give numbers back as text.
        $time = Cache::get(self::KEY);

        return is_numeric($time) ? (int) $time : null;
    }
}
