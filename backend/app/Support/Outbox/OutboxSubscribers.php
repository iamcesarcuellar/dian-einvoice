<?php

namespace App\Support\Outbox;

use InvalidArgumentException;

/**
 * Which queued jobs each outbox event type starts. Modules subscribe in their service providers;
 * an event type without subscribers is only marked as published.
 */
final class OutboxSubscribers
{
    /** @var array<string, list<class-string<ConsumesOutboxEvents>>> */
    private array $jobs = [];

    /**
     * @param  class-string<ConsumesOutboxEvents>  $job
     */
    public function subscribe(string $type, string $job): void
    {
        if (! is_subclass_of($job, ConsumesOutboxEvents::class)) {
            throw new InvalidArgumentException("{$job} does not consume outbox events.");
        }

        $this->jobs[$type][] = $job;
    }

    /**
     * The jobs for one event, ready to dispatch.
     *
     * @return list<ConsumesOutboxEvents>
     */
    public function jobsFor(OutboxEvent $event): array
    {
        return array_map(fn (string $job): ConsumesOutboxEvents => $job::fromOutboxEvent($event), $this->jobs[$event->type] ?? []);
    }
}
