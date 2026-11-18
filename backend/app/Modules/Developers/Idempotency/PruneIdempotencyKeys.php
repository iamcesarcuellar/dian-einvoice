<?php

namespace App\Modules\Developers\Idempotency;

use App\Support\Outbox\Sweeper;

/** Deletes Idempotency-Key records older than their 24 hours, a batch at a time. */
final class PruneIdempotencyKeys implements Sweeper
{
    public function __construct(private readonly IdempotencyStore $store) {}

    public function sweep(): int
    {
        // Pruning is housekeeping, not a repair: it never counts as something restarted.
        $this->store->prune();

        return 0;
    }
}
