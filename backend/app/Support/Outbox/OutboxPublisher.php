<?php

namespace App\Support\Outbox;

/**
 * Where the outbox relay publishes committed events: the local queue (QueueOutboxPublisher) or the topic
 * of document events (SnsOutboxPublisher), chosen by config adapters.outbox_publisher. It runs inside the
 * transaction of the relay that marks the events published, so an exception rolls the batch back and
 * the next batch publishes it again: delivery is at least once, and every consumer is idempotent.
 */
interface OutboxPublisher
{
    /**
     * @param  array<int, OutboxEvent>  $events  In id order.
     */
    public function publish(array $events): void;
}
