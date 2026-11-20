<?php

namespace App\Support\Outbox;

use InvalidArgumentException;

/**
 * Which queued jobs each outbox event type starts, and on which channel. Modules subscribe in their
 * service providers; an event type without subscribers is only marked as published.
 *
 * The channel is the queue a job runs from in the emulated cloud and in production: the topic of
 * document events routes each event type to the SQS queue of every channel with a job for it (the
 * filters of the CDK app come from routes(), through resources/outbox/routes.json). Locally every job
 * goes to the one Redis queue, whatever its channel.
 */
final class OutboxSubscribers
{
    /** The electronic document chain and the delivery to the customer. */
    public const string CHAIN = 'chain';

    /** Webhook events and their deliveries. */
    public const string WEBHOOKS = 'webhooks';

    public const array CHANNELS = [self::CHAIN, self::WEBHOOKS];

    /** @var array<string, array<string, list<class-string<ConsumesOutboxEvents>>>> Type => channel => jobs. */
    private array $jobs = [];

    /**
     * @param  class-string<ConsumesOutboxEvents>  $job
     */
    public function subscribe(string $type, string $job, string $channel = self::CHAIN): void
    {
        if (! is_subclass_of($job, ConsumesOutboxEvents::class)) {
            throw new InvalidArgumentException("{$job} does not consume outbox events.");
        }

        if (! in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException("Unknown outbox channel {$channel}: ".implode(' or ', self::CHANNELS).'.');
        }

        $this->jobs[$type][$channel][] = $job;
    }

    /**
     * The jobs for one event, ready to dispatch: those of one channel, or of every channel.
     *
     * @return list<ConsumesOutboxEvents>
     */
    public function jobsFor(OutboxEvent $event, ?string $channel = null): array
    {
        $byChannel = $this->jobs[$event->type] ?? [];
        $classes = $channel === null ? array_merge(...array_values($byChannel)) : ($byChannel[$channel] ?? []);

        return array_map(fn (string $job): ConsumesOutboxEvents => $job::fromOutboxEvent($event), $classes);
    }

    /**
     * The event types each channel receives, sorted: the routes of the topic to the queues.
     *
     * @return array<string, list<string>>
     */
    public function routes(): array
    {
        $routes = array_fill_keys(self::CHANNELS, []);

        foreach ($this->jobs as $type => $channels) {
            foreach (array_keys($channels) as $channel) {
                $routes[$channel][] = $type;
            }
        }

        return array_map(function (array $types): array {
            sort($types);

            return $types;
        }, $routes);
    }
}
