<?php

namespace App\Support\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publishes outbox events to the queue. Each batch takes the oldest unpublished events in id order
 * with FOR UPDATE SKIP LOCKED, so two relays never take the same rows, dispatches their jobs
 * (delayed until available_at when it is in the future) and marks them published in the same
 * transaction. A crash between the dispatch and the commit publishes those events again: delivery
 * is at least once, and every consumer is idempotent.
 */
final class OutboxRelay
{
    public const int BATCH = 100;

    public function __construct(
        private readonly OutboxSubscribers $subscribers,
        private readonly Dispatcher $bus,
    ) {}

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

            foreach ($events as $event) {
                $this->publish($event);
            }

            OutboxEvent::query()->whereKey($events->modelKeys())->update(['published_at' => Carbon::now()]);

            return $events->count();
        });
    }

    private function publish(OutboxEvent $event): void
    {
        $delayed = $event->available_at !== null && $event->available_at->isFuture();

        foreach ($this->subscribers->jobsFor($event) as $job) {
            if ($delayed && method_exists($job, 'delay')) {
                $job->delay($event->available_at);
            }

            $this->bus->dispatch($job);
        }
    }
}
