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
 * the tables, the secrets, the log events of the functions), plus what only the emulator has: its version,
 * the list of the emails SES took (its /_aws/ses endpoint, as a local mailbox) and the messages of a queue,
 * in flight included (its /_aws/sqs/messages endpoint). It also writes a new version of a secret, as a
 * rotation function does, for the rotation make cloud-check stands in for (the emulator cannot run the
 * rotation functions of AWS). It never runs against AWS: every method refuses when no emulator endpoint is
 * configured, and the roles of the functions do not allow writing a secret.
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

    /**
     * Sends messages to a queue in one SendMessageBatch call, so they arrive together; returns the id of
     * each message, in the order given.
     *
     * @param  list<array{body: string, type?: string}>  $messages  The body, and a type attribute when given.
     * @return list<string>
     */
    public function sendMessages(string $queueUrl, array $messages): array
    {
        $this->endpoint() ?? throw new RuntimeException('No local AWS emulator is configured (AWS_ENDPOINT_URL).');

        $result = $this->sdk->createSqs()->sendMessageBatch([
            'QueueUrl' => $queueUrl,
            'Entries' => array_map(fn (array $message, int $index): array => array_filter([
                'Id' => 'm'.$index,
                'MessageBody' => $message['body'],
                'MessageAttributes' => isset($message['type']) ? [OutboxMessage::TYPE => ['DataType' => 'String', 'StringValue' => $message['type']]] : null,
            ]), $messages, array_keys($messages)),
        ]);

        $ids = [];

        foreach (is_array($result['Successful'] ?? null) ? $result['Successful'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['Id'] ?? null) && is_string($entry['MessageId'] ?? null)) {
                $ids[(int) substr($entry['Id'], 1)] = $entry['MessageId'];
            }
        }

        if (count($ids) !== count($messages)) {
            throw new RuntimeException(sprintf('The queue took %d of %d messages.', count($ids), count($messages)));
        }

        ksort($ids);

        return array_values($ids);
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
     * The stored value of a secret of an identity of the database, every field (username, password, dbname,
     * masterarn...), in its current version.
     *
     * @return array<string, mixed>
     */
    public function secretValue(string $secretId): array
    {
        $this->endpoint() ?? throw new RuntimeException('No local AWS emulator is configured (AWS_ENDPOINT_URL).');
        $value = $this->sdk->createSecretsManager()->getSecretValue(['SecretId' => $secretId])['SecretString'] ?? null;
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            throw new RuntimeException("The secret {$secretId} is not a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Writes a new current version of a secret, as a rotation function does when it finishes: the version
     * that was current becomes AWSPREVIOUS.
     *
     * @param  array<string, mixed>  $value
     */
    public function putSecret(string $secretId, array $value): void
    {
        $this->endpoint() ?? throw new RuntimeException('No local AWS emulator is configured (AWS_ENDPOINT_URL).');

        $this->sdk->createSecretsManager()->putSecretValue([
            'SecretId' => $secretId,
            'SecretString' => json_encode($value, JSON_THROW_ON_ERROR),
            'VersionStages' => ['AWSCURRENT'],
        ]);
    }

    /**
     * How many log events of a function since a moment (milliseconds) hold a text: what its execution
     * environments wrote to its log group.
     */
    public function logEvents(string $function, string $text, int $since): int
    {
        $this->endpoint() ?? throw new RuntimeException('No local AWS emulator is configured (AWS_ENDPOINT_URL).');
        $logs = $this->sdk->createCloudWatchLogs();
        $count = 0;
        $token = null;

        for ($page = 0; $page < 20; $page++) {
            $result = $logs->filterLogEvents(array_filter(['logGroupName' => "/aws/lambda/{$function}", 'startTime' => $since, 'nextToken' => $token]));

            foreach (is_array($result['events'] ?? null) ? $result['events'] : [] as $event) {
                $count += is_array($event) && is_string($event['message'] ?? null) && str_contains($event['message'], $text) ? 1 : 0;
            }

            $token = is_string($result['nextToken'] ?? null) && $result['nextToken'] !== '' ? $result['nextToken'] : null;

            if ($token === null) {
                break;
            }
        }

        return $count;
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

    /** The version of the emulator, from its health endpoint. */
    public function version(): string
    {
        $version = $this->emulator()->get('/_floci/health')->throw()->json('version');

        return is_string($version) ? $version : '';
    }

    /**
     * Whether the emulator keeps the visibility a function set on an SQS record it reported as failed, as
     * AWS does. Floci 2.5.0 and earlier reset it to the visibility timeout of the queue
     * (docs/limitations.md); an unknown version is taken to keep it.
     */
    public function keepsVisibilityOfFailedRecords(): bool
    {
        if (preg_match('/^\d+\.\d+\.\d+/', $this->version(), $match) !== 1) {
            return true;
        }

        return version_compare($match[0], '2.5.0', '>');
    }

    /**
     * The messages of a queue, waiting and in flight, with how many times each was received: a look the
     * emulator gives without receiving them.
     *
     * @return list<array{id: string, receives: int}>
     */
    public function peek(string $queueUrl): array
    {
        $messages = $this->emulator()->get('/_aws/sqs/messages', ['QueueUrl' => $queueUrl])->throw()->json('messages');
        $peeked = [];

        foreach (is_array($messages) ? $messages : [] as $message) {
            if (! is_array($message) || ! is_string($message['MessageId'] ?? null)) {
                continue;
            }

            $attributes = is_array($message['Attributes'] ?? null) ? $message['Attributes'] : [];
            $receives = $attributes['ApproximateReceiveCount'] ?? null;
            $peeked[] = ['id' => $message['MessageId'], 'receives' => is_numeric($receives) ? (int) $receives : 0];
        }

        return $peeked;
    }

    /** Deletes every message of a queue, those in flight included, through the emulator. */
    public function purge(string $queueUrl): void
    {
        $this->emulator()->delete('/_aws/sqs/messages?'.http_build_query(['QueueUrl' => $queueUrl]))->throw();
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
