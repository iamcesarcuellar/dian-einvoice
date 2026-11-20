<?php

namespace App\Support\Outbox;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Publishes outbox events. Each batch claims unpublished events in id order with FOR UPDATE SKIP LOCKED,
 * so two relays, or a relay and the fast path of a writer, never take the same rows; hands them to the
 * publisher (locally the jobs of each event go to the queue, delayed until available_at; in the emulated
 * cloud and in production each event goes to the topic of document events) and marks them published in
 * the same transaction. A crash between the publish and the commit publishes those events again:
 * delivery is at least once, and every consumer is idempotent.
 */
final class OutboxRelay
{
    public const int BATCH = 100;

    public function __construct(private readonly OutboxPublisher $publisher) {}

    /**
     * Publishes up to $limit of the oldest unpublished events, only those recorded at least $olderThan
     * seconds ago when given (the safety net of the functions); returns how many.
     */
    public function publishBatch(int $limit = self::BATCH, ?int $olderThan = null): int
    {
        return $this->claimAndPublish(function (Builder $query) use ($limit, $olderThan): void {
            if ($olderThan !== null) {
                $query->where('created_at', '<=', Carbon::now()->subSeconds($olderThan));
            }

            $query->limit($limit);
        });
    }

    /**
     * Publishes the given events that are still unpublished and not claimed by another relay (the fast
     * path of the writer that recorded them); returns how many.
     *
     * @param  list<string>  $ids
     */
    public function publishEvents(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->claimAndPublish(function (Builder $query) use ($ids): void {
            $query->whereKey($ids);
        });
    }

    /**
     * @param  Closure(Builder<OutboxEvent>): void  $scope
     */
    private function claimAndPublish(Closure $scope): int
    {
        // Read committed: the locking read takes no gap locks, so it never blocks the transactions
        // that are writing new events.
        DB::statement('set transaction isolation level read committed');

        return DB::transaction(function () use ($scope): int {
            $query = OutboxEvent::query()
                ->whereNull('published_at')
                ->orderBy('id')
                ->lock('for update skip locked');

            $scope($query);
            $events = $query->get();

            if ($events->isEmpty()) {
                return 0;
            }

            $this->publisher->publish($events->all());

            OutboxEvent::query()->whereKey($events->modelKeys())->update(['published_at' => Carbon::now()]);

            return $events->count();
        });
    }
}
