<?php

namespace App\Support\Health;

/**
 * The heartbeat of a long-running process of this container (a queue worker, the outbox relay): a
 * file in its temporary directory touched while its loop turns, at most every BEAT seconds. The
 * healthcheck of the container (php artisan health:check worker|relay) reads its age; the file is
 * per container, so the replicas of the worker never share one.
 */
final class Heartbeat
{
    /** Seconds between two touches of the file. */
    public const int BEAT = 10;

    /** @var array<string, int> When each process of this PHP process last touched its file. */
    private static array $last = [];

    public static function beat(string $process): void
    {
        $now = time();

        if (($now - (self::$last[$process] ?? 0)) < self::BEAT) {
            return;
        }

        self::$last[$process] = $now;
        @touch(self::path($process), $now);
    }

    /** Seconds since the last beat, or null when the process never beat in this container. */
    public static function age(string $process): ?int
    {
        clearstatcache(true, self::path($process));
        $time = @filemtime(self::path($process));

        return $time === false ? null : max(0, time() - $time);
    }

    private static function path(string $process): string
    {
        return sys_get_temp_dir().'/dian-einvoice-'.preg_replace('/[^a-z]/', '', $process).'.heartbeat';
    }
}
