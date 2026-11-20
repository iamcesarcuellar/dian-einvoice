<?php

namespace App\Support\Outbox;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The fast path of the outbox in the functions of the emulated cloud and of production, where no relay
 * runs all the time (config outbox.publish_after_commit): the events a process records are published by
 * that same process right after its transaction commits, before it answers the request or finishes the
 * job, so the next step of the chain starts at once instead of waiting for the relay function.
 *
 * The events are claimed as the relay claims them (OutboxRelay, FOR UPDATE SKIP LOCKED), so the two paths
 * never publish the same row at the same time. A publish that fails loses nothing: the rows stay
 * unpublished in the outbox and the relay function publishes them on its next run. Nothing is left for
 * after the answer: AWS freezes a function as soon as it answers.
 */
final class OutboxFastPath
{
    /** @var array<string, true> Events recorded by this process and not handed to the relay yet. */
    private array $recorded = [];

    public function __construct(private readonly OutboxRelay $relay) {}

    public function enabled(): bool
    {
        return config()->boolean('outbox.publish_after_commit');
    }

    /**
     * Called for each event right after it is recorded, inside the transaction of the writer. Outside a
     * transaction the event is already committed and goes out at once.
     */
    public function recorded(OutboxEvent $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->recorded[$event->id] = true;

        // One callback per event: the first that runs after the commit takes every event recorded so
        // far, so a transaction with several events publishes them together. The ids of a transaction
        // that rolled back stay in the list and are claimed for nothing: their rows do not exist.
        DB::afterCommit(fn () => $this->publishRecorded());
    }

    /** Publishes the events this process recorded; returns how many went out. */
    public function publishRecorded(): int
    {
        if ($this->recorded === []) {
            return 0;
        }

        $ids = array_keys($this->recorded);
        $this->recorded = [];

        try {
            return $this->relay->publishEvents($ids);
        } catch (Throwable $exception) {
            // The change is committed and its events are safe in the outbox: report and go on.
            report($exception);

            return 0;
        }
    }
}
