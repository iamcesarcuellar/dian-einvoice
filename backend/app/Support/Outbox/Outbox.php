<?php

namespace App\Support\Outbox;

use DateTimeInterface;

/**
 * Records integration events in the transaction of the change they describe, so an event exists
 * if and only if the change was committed. The relay (outbox:relay) publishes them to the queue,
 * at least once: every consumer must be idempotent.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  DateTimeInterface|null  $availableAt  Not to be handled before this moment, such as a retry.
     */
    public function record(string $companyId, string $aggregateType, string $aggregateId, string $type, array $payload = [], ?DateTimeInterface $availableAt = null): OutboxEvent
    {
        return OutboxEvent::create([
            'company_id' => $companyId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'type' => $type,
            'payload' => $payload,
            'available_at' => $availableAt,
        ]);
    }
}
