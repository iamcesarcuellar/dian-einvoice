<?php

namespace App\Support\Outbox;

use App\Support\Observability\Correlation;
use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;
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
 * delivery is at least once, and every consumer is idempotent. In the functions, each event the safety net
 * publishes writes a line in its own journey (outbox.relayed): a writer that did not publish it.
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
        $events = $this->claimAndPublish(function (Builder $query) use ($limit, $olderThan): void {
            if ($olderThan !== null) {
                $query->where('created_at', '<=', Carbon::now()->subSeconds($olderThan));
            }

            $query->limit($limit);
        });

        // The safety net of the functions: each of these is an event its writer left behind.
        if ($olderThan !== null) {
            foreach ($events as $event) {
                Correlation::within($event->correlationId(), fn () => Telemetry::record(
                    LogEvent::OutboxRelayed,
                    sprintf('The relay published a %s event its writer left behind.', $event->type),
                    outcome: 'success',
                    durationMs: max(0.0, $event->created_at->diffInMilliseconds(Carbon::now())),
                    detail: ['event_type' => $event->type, 'path' => 'relay'],
                    level: 'warning',
                ));
            }
        }

        return count($events);
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

        return count($this->claimAndPublish(function (Builder $query) use ($ids): void {
            $query->whereKey($ids);
        }));
    }

    /**
     * The events claimed, published and marked published.
     *
     * @param  Closure(Builder<OutboxEvent>): void  $scope
     * @return list<OutboxEvent>
     */
    private function claimAndPublish(Closure $scope): array
    {
        // Read committed: the locking read takes no gap locks, so it never blocks the transactions
        // that are writing new events.
        DB::statement('set transaction isolation level read committed');

        return DB::transaction(function () use ($scope): array {
            $query = OutboxEvent::query()
                ->whereNull('published_at')
                ->orderBy('id')
                ->lock('for update skip locked');

            $scope($query);
            $events = $query->get();

            if ($events->isEmpty()) {
                return [];
            }

            $this->publisher->publish($events->all());

            OutboxEvent::query()->whereKey($events->modelKeys())->update(['published_at' => Carbon::now()]);

            return array_values($events->all());
        });
    }
}
