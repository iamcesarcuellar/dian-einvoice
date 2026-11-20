<?php

namespace App\Modules\Developers\RateLimits;

/**
 * The result of one request against a window limit: whether it fits, how many requests are left and
 * when the window ends (Unix time).
 */
final readonly class WindowCount
{
    public function __construct(
        public bool $allowed,
        public int $remaining,
        public int $resetsAt,
    ) {}
}
