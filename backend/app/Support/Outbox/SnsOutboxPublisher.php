<?php

namespace App\Support\Outbox;

use Aws\Sns\SnsClient;
use RuntimeException;

/**
 * SNS adapter of OutboxPublisher: every event goes to the topic of document events, ten at a time
 * (PublishBatch), with its type as a message attribute. The subscriptions of the topic route each type
 * to the SQS queue of every channel with a job for it (OutboxSubscribers::routes()). An entry the topic
 * refuses fails the whole batch, which the relay publishes again: at least once.
 */
final class SnsOutboxPublisher implements OutboxPublisher
{
    /** Entries per PublishBatch call, the most SNS takes. */
    private const int BATCH = 10;

    public function __construct(
        private readonly SnsClient $sns,
        private readonly string $topicArn,
    ) {}

    public function publish(array $events): void
    {
        foreach (array_chunk($events, self::BATCH) as $chunk) {
            $result = $this->sns->publishBatch([
                'TopicArn' => $this->topicArn,
                'PublishBatchRequestEntries' => array_map(fn (OutboxEvent $event): array => OutboxMessage::entry($event), $chunk),
            ]);

            /** @var list<array{Id?: string, Code?: string, Message?: string}> $failed */
            $failed = $result['Failed'] ?? [];

            if ($failed !== []) {
                throw new RuntimeException(sprintf(
                    'The topic of document events refused %d of %d events, such as %s: %s %s.',
                    count($failed), count($chunk), $failed[0]['Id'] ?? '?', $failed[0]['Code'] ?? '', $failed[0]['Message'] ?? '',
                ));
            }
        }
    }
}
