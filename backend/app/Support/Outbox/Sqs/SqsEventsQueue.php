<?php

namespace App\Support\Outbox\Sqs;

use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\UnreadableMessage;
use Aws\Sqs\SqsClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\SqsQueue;
use JsonException;

/**
 * The SQS queues of the emulated cloud and of production, through Laravel's queue: queue:work sqs
 * --queue=chain (or webhooks) consumes one queue with the same workers, timeouts and handlers as the local
 * Redis queue. A message is one of two kinds:
 *
 *  - an outbox event the topic of document events routed here (attribute "type"): it becomes the job its
 *    channel subscribed to that type, built with fromOutboxEvent() exactly as the local relay builds it.
 *    One scheduled for later (available_at) is left invisible in the queue until then;
 *  - a job the application dispatched to the queue itself, such as an invitation email: Laravel's own
 *    payload, run as it is.
 *
 * Retries and the dead-letter queue are those of SQS: a job that throws becomes visible again after the
 * wait of its backoff (ChangeMessageVisibility), each receive counts as an attempt, and after the
 * maxReceiveCount of the redrive policy of the queue SQS moves the message to its dead-letter queue (see
 * SqsEventJob). A message no handler can take fails on every receive and ends there too.
 */
final class SqsEventsQueue extends SqsQueue
{
    /** @var array<string, int|null> Queue URL => maxReceiveCount of its redrive policy. */
    private array $maxReceives = [];

    /**
     * @param  array<string, string>  $queues  Channel => queue URL.
     */
    public function __construct(
        SqsClient $sqs,
        private readonly array $queues,
        private readonly OutboxSubscribers $subscribers,
        private readonly int $waitSeconds,
        string $default = OutboxSubscribers::CHAIN,
    ) {
        parent::__construct($sqs, $default);
    }

    /**
     * The URL of a queue: a channel (chain, webhooks) names its queue; anything else is an SQS URL or name.
     *
     * @param  string|null  $queue
     */
    public function getQueue($queue): string
    {
        $name = is_string($queue) && $queue !== '' ? $queue : (string) $this->default;

        return $this->queues[$name] ?? (string) parent::getQueue($name);
    }

    /**
     * @param  string|null  $queue
     */
    public function pop($queue = null): ?Job
    {
        $url = $this->getQueue($queue);

        $response = $this->sqs->receiveMessage([
            'QueueUrl' => $url,
            'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
            'MessageAttributeNames' => ['All'],
            'MaxNumberOfMessages' => 1,
            'WaitTimeSeconds' => $this->waitSeconds,
        ]);

        $messages = $response['Messages'] ?? null;
        $message = is_array($messages) && is_array($messages[0] ?? null) ? $messages[0] : null;

        if ($message === null) {
            return null;
        }

        $maxReceives = $this->maxReceives($url);

        try {
            $payload = $this->payloadOf($message, $url);
        } catch (UnreadableMessage $exception) {
            $payload = $this->createPayload(new RejectUnreadableMessage($url, $this->messageId($message), $exception->getMessage()), $url);
        }

        if ($payload === null) {
            return null;
        }

        return new SqsEventJob($this->container, $this->sqs, $message, $this->connectionName, $url, $payload, $maxReceives);
    }

    /**
     * The Laravel payload of a message, or null when its event is due later and it was left invisible.
     *
     * @param  array<mixed>  $message
     *
     * @throws UnreadableMessage
     */
    private function payloadOf(array $message, string $url): ?string
    {
        $event = OutboxMessage::fromSqs($message);

        if ($event === null) {
            return $this->ownPayload($message);
        }

        if (($wait = OutboxMessage::secondsUntilAvailable($event)) > 0) {
            $this->sqs->changeMessageVisibility([
                'QueueUrl' => $url,
                'ReceiptHandle' => is_string($message['ReceiptHandle'] ?? null) ? $message['ReceiptHandle'] : '',
                'VisibilityTimeout' => $wait,
            ]);

            return null;
        }

        $channel = array_search($url, $this->queues, true);
        $jobs = is_string($channel) ? $this->subscribers->jobsFor($event, $channel) : [];

        if (count($jobs) !== 1) {
            throw new UnreadableMessage(sprintf('%d jobs of the %s queue take events of type %s; exactly one must.', count($jobs), is_string($channel) ? $channel : $url, $event->type));
        }

        return $this->createPayload($jobs[0], $url);
    }

    /**
     * A job the application dispatched to this queue: Laravel's own payload.
     *
     * @param  array<mixed>  $message
     *
     * @throws UnreadableMessage
     */
    private function ownPayload(array $message): string
    {
        $body = is_string($message['Body'] ?? null) ? $message['Body'] : '';

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnreadableMessage('a message without a type attribute is not a queued job: its body is not JSON.');
        }

        if (! is_array($payload) || ! is_string($payload['job'] ?? null) || ! is_array($payload['data'] ?? null)) {
            throw new UnreadableMessage('a message without a type attribute is not a queued job of the application.');
        }

        return $body;
    }

    /** The maxReceiveCount of the redrive policy of a queue, read once; null when it has none. */
    private function maxReceives(string $url): ?int
    {
        if (! array_key_exists($url, $this->maxReceives)) {
            $attributes = $this->sqs->getQueueAttributes(['QueueUrl' => $url, 'AttributeNames' => ['RedrivePolicy']])['Attributes'] ?? [];
            $policy = is_array($attributes) && is_string($attributes['RedrivePolicy'] ?? null) ? json_decode($attributes['RedrivePolicy'], true) : null;
            $count = is_array($policy) ? ($policy['maxReceiveCount'] ?? null) : null;

            $this->maxReceives[$url] = is_numeric($count) ? (int) $count : null;
        }

        return $this->maxReceives[$url];
    }

    /**
     * @param  array<mixed>  $message
     */
    private function messageId(array $message): string
    {
        return is_string($message['MessageId'] ?? null) ? $message['MessageId'] : 'unknown';
    }
}
