<?php

namespace App\Support\Outbox;

/**
 * Records integration events in the transaction of the change they describe, so an event exists
 * if and only if the change was committed. Publishing them arrives in later stages.
 */
final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $companyId, string $aggregateType, string $aggregateId, string $type, array $payload = []): OutboxEvent
    {
        return OutboxEvent::create([
            'company_id' => $companyId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
