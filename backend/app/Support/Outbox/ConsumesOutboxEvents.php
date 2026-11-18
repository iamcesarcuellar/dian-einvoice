<?php

namespace App\Support\Outbox;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued job the relay builds from an outbox event. Delivery is at least once, so handling the
 * same event twice, or two events for the same change at once, must not repeat any work.
 */
interface ConsumesOutboxEvents extends ShouldQueue
{
    public static function fromOutboxEvent(OutboxEvent $event): self;
}
