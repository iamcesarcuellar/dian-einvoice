<?php

namespace App\Support\Outbox;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Holds back the relays and the sweepers for a while, through the shared cache: the demo seeder runs the
 * chain of its documents in line (InlineChain) and must be the only one taking their events. Locally
 * make seed also stops the relay process; the functions have no process to stop, so the relay function
 * and the scheduled sweepers look at this instead. The pause ends when the seeder resumes, or by itself
 * after its time is up.
 */
final class OutboxPause
{
    private const string KEY = 'outbox:paused';

    public function __construct(private readonly Cache $cache) {}

    public function pause(int $seconds): void
    {
        $this->cache->put(self::KEY, true, max(1, $seconds));
    }

    public function resume(): void
    {
        $this->cache->forget(self::KEY);
    }

    public function active(): bool
    {
        return $this->cache->get(self::KEY) === true;
    }
}
