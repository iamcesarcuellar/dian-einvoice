<?php

namespace App\Support\Outbox;

use App\Support\Observability\Correlation;
use DateTimeInterface;

/**
 * Records integration events in the transaction of the change they describe, so an event exists
 * if and only if the change was committed. A relay publishes them, at least once: every consumer must
 * be idempotent. Locally the relay process (outbox:relay) does it; in the functions of the emulated
 * cloud and of production the writer publishes its own events right after the commit (OutboxFastPath)
 * and the relay function publishes what was left behind (outbox:publish-pending). Each event keeps the
 * correlation id of the journey that recorded it, which its messages and jobs carry on.
 */
final class Outbox
{
    public function __construct(private readonly OutboxFastPath $fastPath) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  DateTimeInterface|null  $availableAt  Not to be handled before this moment, such as a retry.
     */
    public function record(string $companyId, string $aggregateType, string $aggregateId, string $type, array $payload = [], ?DateTimeInterface $availableAt = null): OutboxEvent
    {
        $event = OutboxEvent::create([
            'company_id' => $companyId,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'type' => $type,
            'correlation_id' => Correlation::ensure(),
            'payload' => $payload,
            'available_at' => $availableAt,
        ]);

        $this->fastPath->recorded($event);

        return $event;
    }
}
