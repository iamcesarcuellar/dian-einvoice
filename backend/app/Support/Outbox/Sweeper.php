<?php

namespace App\Support\Outbox;

/**
 * A periodic task the relay runs between batches, such as recording again the event of a chain
 * that stopped moving. Tag implementations with Sweeper::class in a service provider.
 */
interface Sweeper
{
    /** Runs once; returns how many things it fixed. */
    public function sweep(): int;
}
