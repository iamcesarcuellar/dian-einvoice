<?php

namespace App\Modules\Developers\RateLimits;

/**
 * Counts requests in fixed windows that start with the first request of a key: RedisWindowLimiter
 * locally, CacheWindowLimiter (the limits table of DynamoDB) in the emulated cloud, chosen by config
 * adapters.api_key_limits.
 */
interface WindowLimiter
{
    /** Counts one request of the key and says whether it fits in the limit of its current window. */
    public function hit(string $key, int $limit, int $seconds): WindowCount;
}
