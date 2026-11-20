<?php

namespace App\Support\Cloud;

use App\Support\Outbox\OutboxMessage;
use App\Support\Secrets\SecretsManagerSecrets;
use Aws\Sdk;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A development tool for make cloud-check and make cloud-demo: what the local AWS emulator holds, read
 * through the same AWS APIs the adapters use (queue depths, dead letters, objects of the bucket, items of
 * the tables, the secret), plus the one thing only the emulator has, the list of the emails SES took
 * (its /_aws/ses endpoint, as a local mailbox). It never runs against AWS: every method refuses when no
 * emulator endpoint is configured.
 */
final class EmulatedCloud
{
    /**
     * @param  array<string, mixed>  $aws  config('aws')
     */
    public function __construct(
        private readonly Sdk $sdk,
        private readonly array $aws,
    ) {}

    /** Whether this process runs in the emulated cloud: AWS adapters pointed at the local emulator. */
    public function enabled(): bool
    {
        return $this->endpoint() !== null && config('adapters.outbox_publisher') === 'sns';
    }

    /**
     * Messages waiting, in flight and delayed in a queue.
     *
     * @return array{visible: int, in_flight: int, delayed: int}
     */
    public function depth(string $queueUrl): array
    {
        $attributes = $this->sdk->createSqs()->getQueueAttributes([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible', 'ApproximateNumberOfMessagesDelayed'],
        ])['Attributes'] ?? [];

        $count = fn (string $name): int => is_array($attributes) && is_numeric($attributes[$name] ?? null) ? (int) $attributes[$name] : 0;

        return [
            'visible' => $count('ApproximateNumberOfMessages'),
            'in_flight' => $count('ApproximateNumberOfMessagesNotVisible'),
            'delayed' => $count('ApproximateNumberOfMessagesDelayed'),
        ];
    }

    /**
     * Takes the messages of a dead-letter queue whose body holds the marker, deleting them; the others
     * become visible again at once.
     *
     * @return list<array{body: string, type: string|null, receives: int}>
     */
    public function takeDeadLetters(string $queueUrl, string $marker): array
    {
        $sqs = $this->sdk->createSqs();
        $taken = [];

        for ($round = 0; $round < 5; $round++) {
            $messages = $sqs->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => 10,
                'MessageSystemAttributeNames' => ['ApproximateReceiveCount'],
                'MessageAttributeNames' => ['All'],
                'WaitTimeSeconds' => 1,
            ])['Messages'] ?? [];

            if (! is_array($messages) || $messages === []) {
                break;
            }

            foreach ($messages as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $body = is_string($message['Body'] ?? null) ? $message['Body'] : '';
                $handle = is_string($message['ReceiptHandle'] ?? null) ? $message['ReceiptHandle'] : '';

                if (! str_contains($body, $marker)) {
                    $sqs->changeMessageVisibility(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $handle, 'VisibilityTimeout' => 0]);

                    continue;
                }

                $attributes = is_array($message['MessageAttributes'] ?? null) ? $message['MessageAttributes'] : [];
                $type = is_array($attributes[OutboxMessage::TYPE] ?? null) ? ($attributes[OutboxMessage::TYPE]['StringValue'] ?? null) : null;
                $system = is_array($message['Attributes'] ?? null) ? $message['Attributes'] : [];
                $receives = $system['ApproximateReceiveCount'] ?? 0;
                $taken[] = ['body' => $body, 'type' => is_string($type) ? $type : null, 'receives' => is_numeric($receives) ? (int) $receives : 0];
                $sqs->deleteMessage(['QueueUrl' => $queueUrl, 'ReceiptHandle' => $handle]);
            }
        }

        return $taken;
    }

    /** Publishes a message to the topic of document events with the given type attribute; returns its id. */
    public function publish(string $type, string $body): string
    {
        $topic = $this->aws['events_topic'] ?? null;

        if (! is_string($topic) || $topic === '') {
            throw new RuntimeException('EVENTS_TOPIC_ARN is not set.');
        }

        $result = $this->sdk->createSns()->publish([
            'TopicArn' => $topic,
            'Message' => $body,
            'MessageAttributes' => [OutboxMessage::TYPE => ['DataType' => 'String', 'StringValue' => $type]],
        ]);

        return is_string($result['MessageId'] ?? null) ? $result['MessageId'] : '';
    }

    /**
     * Keys of the current objects of the documents bucket under a prefix.
     *
     * @return list<string>
     */
    public function objectKeys(string $prefix): array
    {
        $bucket = $this->aws['documents_bucket'] ?? null;

        if (! is_string($bucket) || $bucket === '') {
            throw new RuntimeException('DOCUMENTS_BUCKET is not set.');
        }

        $keys = [];

        foreach ($this->sdk->createS3()->getPaginator('ListObjectsV2', ['Bucket' => $bucket, 'Prefix' => $prefix]) as $page) {
            foreach (is_array($page['Contents'] ?? null) ? $page['Contents'] : [] as $object) {
                if (is_array($object) && is_string($object['Key'] ?? null)) {
                    $keys[] = $object['Key'];
                }
            }
        }

        return $keys;
    }

    /** How many items a table holds, up to the limit. */
    public function items(string $table, int $limit = 100): int
    {
        $items = $this->sdk->createDynamoDb()->scan(['TableName' => $table, 'Limit' => $limit])['Items'] ?? [];

        return is_array($items) ? count($items) : 0;
    }

    /**
     * The settings the secret of the application holds.
     *
     * @return array<string, string>
     */
    public function secret(): array
    {
        $secret = $this->aws['app_secret'] ?? null;

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('APP_SECRETS_ID is not set.');
        }

        return (new SecretsManagerSecrets($this->sdk->createSecretsManager()))->read($secret);
    }

    /**
     * The emails SES took, as the emulator keeps them: sender, recipients and subject.
     *
     * @return list<array{id: string, from: string, to: list<string>, subject: string}>
     */
    public function sentEmails(): array
    {
        $messages = $this->emulator()->get('/_aws/ses')->throw()->json('messages');
        $emails = [];

        foreach (is_array($messages) ? $messages : [] as $message) {
            if (! is_array($message)) {
                continue;
            }

            // A raw email (Laravel sends raw MIME through the SES v2 API) is kept in base64.
            $raw = is_string($message['RawData'] ?? null) ? $message['RawData'] : '';
            $decoded = base64_decode($raw, true);
            $raw = is_string($decoded) && str_contains($decoded, ':') ? $decoded : $raw;
            $destination = is_array($message['Destination'] ?? null) ? $message['Destination'] : [];
            $to = is_array($destination['ToAddresses'] ?? null) ? array_values(array_filter($destination['ToAddresses'], 'is_string')) : [];

            $emails[] = [
                'id' => is_string($message['Id'] ?? null) ? $message['Id'] : '',
                'from' => is_string($message['Source'] ?? null) ? $message['Source'] : $this->header($raw, 'From'),
                'to' => $to !== [] ? $to : array_values(array_filter(array_map('trim', explode(',', $this->header($raw, 'To'))))),
                'subject' => is_string($message['Subject'] ?? null) ? $message['Subject'] : $this->header($raw, 'Subject'),
            ];
        }

        return $emails;
    }

    /** Forgets every email SES took, as make seed clears the local mailbox. */
    public function clearSentEmails(): void
    {
        $this->emulator()->delete('/_aws/ses')->throw();
    }

    /** A header of a raw email, unfolded and decoded. */
    private function header(string $raw, string $name): string
    {
        $head = preg_split("/\r?\n\r?\n/", $raw, 2)[0] ?? '';
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $head) ?? '';

        if (preg_match('/^'.preg_quote($name, '/').':\s*(.*)$/mi', $unfolded, $match) !== 1) {
            return '';
        }

        $value = iconv_mime_decode(trim($match[1]), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return is_string($value) ? $value : trim($match[1]);
    }

    private function emulator(): PendingRequest
    {
        $endpoint = $this->endpoint() ?? throw new RuntimeException('No local AWS emulator is configured (AWS_ENDPOINT_URL).');

        return Http::baseUrl($endpoint)->acceptJson()->connectTimeout(2)->timeout(10);
    }

    private function endpoint(): ?string
    {
        $endpoint = $this->aws['endpoint'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? rtrim($endpoint, '/') : null;
    }
}
