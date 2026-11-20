<?php

namespace App\Support\Outbox;

use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Local adapter of OutboxPublisher: the jobs every subscriber of an event starts are dispatched to the
 * queue (Redis), delayed until available_at when it is in the future.
 */
final class QueueOutboxPublisher implements OutboxPublisher
{
    public function __construct(
        private readonly OutboxSubscribers $subscribers,
        private readonly Dispatcher $bus,
    ) {}

    public function publish(array $events): void
    {
        foreach ($events as $event) {
            $delayed = $event->available_at !== null && $event->available_at->isFuture();

            foreach ($this->subscribers->jobsFor($event) as $job) {
                if ($delayed && method_exists($job, 'delay')) {
                    $job->delay($event->available_at);
                }

                $this->bus->dispatch($job);
            }
        }
    }
}
