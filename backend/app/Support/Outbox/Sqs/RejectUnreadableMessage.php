<?php

namespace App\Support\Outbox\Sqs;

use App\Support\Outbox\UnreadableMessage;

/**
 * The job of a message no handler can take. It fails on every receive, a second apart, so the redrive
 * policy of its queue moves the message to the dead-letter queue, where it waits to be read.
 */
final class RejectUnreadableMessage
{
    /** @var list<int> */
    public array $backoff = [1];

    public function __construct(
        public readonly string $queue,
        public readonly string $messageId,
        public readonly string $reason,
    ) {}

    public function handle(): never
    {
        throw new UnreadableMessage("Message {$this->messageId} of {$this->queue} cannot be processed: {$this->reason}");
    }
}
