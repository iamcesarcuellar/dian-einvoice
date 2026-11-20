<?php

namespace App\Modules\Developers\RateLimits;

use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Redis\Limiters\DurationLimiter;

/**
 * Local adapter of WindowLimiter: Laravel's DurationLimiter, one Lua script in Redis per request.
 */
final class RedisWindowLimiter implements WindowLimiter
{
    public function __construct(private readonly Redis $redis) {}

    public function hit(string $key, int $limit, int $seconds): WindowCount
    {
        $limiter = new DurationLimiter($this->redis->connection(), $key, $limit, $seconds);
        $allowed = $limiter->acquire();

        return new WindowCount($allowed, max(0, (int) $limiter->remaining), (int) $limiter->decaysAt);
    }
}
