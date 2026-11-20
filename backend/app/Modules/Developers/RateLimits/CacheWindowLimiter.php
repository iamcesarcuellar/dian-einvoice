<?php

namespace App\Modules\Developers\RateLimits;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Carbon;

/**
 * Cloud adapter of WindowLimiter: Laravel's rate limiter over the store of the rate limiters
 * (cache.limiter), the limits table of DynamoDB in the emulated cloud. The window and its counter are
 * created with conditional writes and the counter grows with an atomic increment, so simultaneous
 * requests never share a count; the items expire on their own (TTL).
 */
final class CacheWindowLimiter implements WindowLimiter
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function hit(string $key, int $limit, int $seconds): WindowCount
    {
        $hits = $this->limiter->hit($key, $seconds);

        return new WindowCount($hits <= $limit, max(0, $limit - $hits), Carbon::now()->getTimestamp() + max(1, $this->limiter->availableIn($key)));
    }
}
