<?php

namespace App\Support\Outbox;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publishes outbox events. Each batch takes the oldest unpublished events in id order with FOR UPDATE
 * SKIP LOCKED, so two relays never take the same rows, hands them to the publisher (locally the jobs of
 * each event go to the queue, delayed until available_at; in the emulated cloud each event goes to the
 * topic of document events) and marks them published in the same transaction. A crash between the
 * publish and the commit publishes those events again: delivery is at least once, and every consumer
 * is idempotent.
 */
final class OutboxRelay
{
    public const int BATCH = 100;

    public function __construct(private readonly OutboxPublisher $publisher) {}

    /** Publishes up to $limit events; returns how many. */
    public function publishBatch(int $limit = self::BATCH): int
    {
        // Read committed: the locking read takes no gap locks, so it never blocks the transactions
        // that are writing new events.
        DB::statement('set transaction isolation level read committed');

        return DB::transaction(function () use ($limit): int {
            $events = OutboxEvent::query()
                ->whereNull('published_at')
                ->orderBy('id')
                ->limit($limit)
                ->lock('for update skip locked')
                ->get();

            if ($events->isEmpty()) {
                return 0;
            }

            $this->publisher->publish($events->all());

            OutboxEvent::query()->whereKey($events->modelKeys())->update(['published_at' => Carbon::now()]);

            return $events->count();
        });
    }
}
