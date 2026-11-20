<?php

namespace Tests\Integration\Operations;

use App\Modules\Developers\RateLimits\CacheWindowLimiter;
use App\Modules\Developers\Webhooks\PublishWebhookEvent;
use App\Modules\Dian\Pipeline\GenerateXml;
use App\Modules\Dian\Pipeline\SignXml;
use App\Support\Keys\KmsKeyStore;
use App\Support\Keys\KmsKeyStoreEntry;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxRoutesCommand;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\SnsOutboxPublisher;
use App\Support\Outbox\Sqs\RejectUnreadableMessage;
use App\Support\Outbox\Sqs\SqsEventJob;
use App\Support\Outbox\Sqs\SqsEventsQueue;
use App\Support\Outbox\UnreadableMessage;
use App\Support\Secrets\SecretsManagerSecrets;
use App\Support\Storage\S3DocumentStorage;
use Aws\CommandInterface;
use Aws\Kms\Exception\KmsException;
use Aws\Kms\KmsClient;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\IntegrationTestCase;
use Throwable;

/**
 * The AWS adapters of the emulated cloud where they are cheap to test, with the AWS SDK answering from
 * memory: the mapping of outbox events to messages and back, the SQS queue that turns a message into the
 * job of its channel, the KMS key store (envelope encryption), the S3 document storage, the routes the
 * CDK app builds the subscriptions from, the rate limit of API keys in a cache store, and the secret of
 * the application. make cloud-check runs them against the local emulator.
 */
final class CloudAdaptersTest extends IntegrationTestCase
{
    public function test_an_outbox_event_becomes_a_message_with_its_type_attribute_and_back(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $event = $this->event('electronic_document.transmit_due', ['document_id' => 'doc', 'run' => 2, 'attempt' => 3], availableAt: '2026-11-20 10:00:45');

        $entry = OutboxMessage::entry($event);
        $this->assertSame($event->id, $entry['Id']);
        $this->assertSame(['type' => ['DataType' => 'String', 'StringValue' => 'electronic_document.transmit_due'], 'company_id' => ['DataType' => 'String', 'StringValue' => $event->company_id]], $entry['MessageAttributes']);

        // Raw delivery: the SQS message has the body and the attributes the topic got.
        $received = OutboxMessage::fromSqs(['Body' => $entry['Message'], 'MessageAttributes' => $entry['MessageAttributes']]);
        $this->assertInstanceOf(OutboxEvent::class, $received);

        foreach (['id', 'company_id', 'aggregate_type', 'aggregate_id', 'type', 'payload'] as $attribute) {
            $this->assertSame($event->getAttribute($attribute), $received->getAttribute($attribute), $attribute);
        }

        $this->assertSame('2026-11-20 09:59:00', $received->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(45, OutboxMessage::secondsUntilAvailable($received));
        $this->assertSame(0, OutboxMessage::secondsUntilAvailable($received, Carbon::parse('2026-11-20 10:01:00')));

        // The jobs read it as they read a row of the table.
        $this->assertEquals(GenerateXml::fromOutboxEvent($event), GenerateXml::fromOutboxEvent($received));

        // An empty payload stays an object; a job the application dispatched itself has no type attribute.
        $this->assertStringContainsString('"payload":{}', OutboxMessage::body($this->event('dian.taxpayer_changed')));
        $this->assertNull(OutboxMessage::fromSqs(['Body' => '{"job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","data":{}}', 'MessageAttributes' => []]));
    }

    public function test_a_message_that_is_not_a_valid_event_is_refused(): void
    {
        $type = ['type' => ['DataType' => 'String', 'StringValue' => 'electronic_document.sign_due']];
        $valid = json_decode(OutboxMessage::body($this->event('electronic_document.sign_due')), true);
        $this->assertIsArray($valid);

        foreach ([
            'not JSON' => '{"marker": "x", not an event',
            'no id' => json_encode([...$valid, 'id' => null]),
            'another type' => json_encode([...$valid, 'type' => 'invoice.issued']),
            'no payload' => json_encode([...$valid, 'payload' => 'text']),
        ] as $case => $body) {
            try {
                OutboxMessage::fromSqs(['Body' => $body, 'MessageAttributes' => $type]);
                $this->fail("A message with {$case} was read.");
            } catch (UnreadableMessage) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_sns_publisher_sends_ten_events_a_call_and_fails_the_batch_when_one_is_refused(): void
    {
        $calls = [];
        $sns = new SnsClient($this->client(function (CommandInterface $command) use (&$calls): Result {
            $entries = $this->list($command, 'PublishBatchRequestEntries');
            $calls[] = count($entries);
            $first = $this->text($this->list($entries, 0)['Id'] ?? null);

            return new Result(count($calls) === 3 ? ['Successful' => [], 'Failed' => [['Id' => $first, 'Code' => 'InternalError', 'Message' => 'synthetic']]] : ['Successful' => [], 'Failed' => []]);
        }));

        $events = array_map(fn (int $index): OutboxEvent => $this->event('invoice.issued', ['n' => $index]), range(1, 23));
        $publisher = new SnsOutboxPublisher($sns, 'arn:aws:sns:us-east-1:000000000000:synthetic');

        try {
            $publisher->publish($events);
            $this->fail('A refused entry did not fail the batch.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('refused 1 of 3 events', $exception->getMessage());
        }

        $this->assertSame([10, 10, 3], $calls);
    }

    public function test_the_sqs_queue_turns_each_message_into_the_job_of_its_channel_with_the_retries_of_the_redrive_policy(): void
    {
        $queues = ['chain' => 'http://floci:4566/000000000000/chain', 'webhooks' => 'http://floci:4566/000000000000/webhooks'];
        $visibility = [];

        $sqs = new SqsClient($this->client(function (CommandInterface $command) use (&$visibility): Result {
            return match ($command->getName()) {
                'GetQueueAttributes' => new Result(['Attributes' => ['RedrivePolicy' => '{"deadLetterTargetArn":"arn:aws:sqs:us-east-1:000000000000:chain-dlq","maxReceiveCount":10}']]),
                'ChangeMessageVisibility' => (function () use ($command, &$visibility): Result {
                    $visibility[] = [$this->text($command['ReceiptHandle']), $command['VisibilityTimeout']];

                    return new Result([]);
                })(),
                default => throw new InvalidArgumentException('Unexpected '.$command->getName()),
            };
        }));

        $queue = new SqsEventsQueue($sqs, $queues, app(OutboxSubscribers::class));
        $queue->setContainer(app());
        $queue->setConnectionName('sqs');

        // invoice.issued starts the generation of the XML on the chain queue and a webhook event on the
        // other; the attempts of a job are the receives of its message, up to the maxReceiveCount.
        $issued = $this->event('invoice.issued', ['document_id' => 'doc', 'run' => 1]);
        $job = $queue->jobFor($this->message($issued, 'issued-1', receives: 3), $queues['chain']);
        $this->assertSame(GenerateXml::class, $this->commandOf($job));
        $this->assertInstanceOf(SqsEventJob::class, $job);
        $this->assertSame(10, $job->maxTries());
        $this->assertSame(3, $job->attempts());
        $this->assertSame(PublishWebhookEvent::class, $this->commandOf($queue->jobFor($this->message($issued, 'issued-2'), $queues['webhooks'])));

        // A transmission retry for later stays invisible in the queue until it is due.
        Carbon::setTestNow('2026-11-20 10:00:00');
        $this->assertNull($queue->jobFor($this->message($this->event('electronic_document.transmit_due', ['document_id' => 'doc'], availableAt: '2026-11-20 10:00:15'), 'retry'), $queues['chain']));
        $this->assertSame([['retry', 15]], $visibility);

        // A message no job of the queue takes (webhook.delivery_due belongs to the webhooks channel), or
        // that is not an event at all, fails on every receive and ends in the dead-letter queue.
        foreach ([$this->message($this->event('webhook.delivery_due'), 'misrouted'), ['MessageId' => 'm', 'ReceiptHandle' => 'garbage', 'Body' => 'garbage', 'Attributes' => ['ApproximateReceiveCount' => '1']]] as $message) {
            $this->assertSame(RejectUnreadableMessage::class, $this->commandOf($queue->jobFor($message, $queues['chain'])));
        }

        $this->assertSame(SignXml::class, $this->commandOf($queue->jobFor($this->message($this->event('electronic_document.sign_due', ['document_id' => 'doc']), 'sign'), $queues['chain'])));

        // The queues are consumed by the consumer functions, never by queue:work; a Lambda record names
        // its queue by ARN.
        $this->assertSame($queues['webhooks'], $queue->urlOfArn('arn:aws:sqs:us-east-1:000000000000:webhooks'));
        $this->assertThrows(fn () => $queue->urlOfArn('arn:aws:sqs:us-east-1:000000000000:elsewhere'), RuntimeException::class);
        $this->assertThrows(fn () => $queue->pop('chain'), LogicException::class);
    }

    public function test_the_kms_key_store_encrypts_each_certificate_with_a_data_key_of_its_own(): void
    {
        $company = $this->company();
        $other = $this->company('Synthetic Other Company', 'OTH');
        $this->actingIn($company);
        $keys = [];

        // KMS from memory: a random data key per call, which it decrypts only with the same encryption context.
        $kms = new KmsClient($this->client(function (CommandInterface $command) use (&$keys): Result|KmsException {
            if ($command->getName() === 'GenerateDataKey') {
                $blob = 'blob-'.Str::random(12);
                $keys[$blob] = [random_bytes(32), $command['EncryptionContext']];

                return new Result(['Plaintext' => $keys[$blob][0], 'CiphertextBlob' => $blob, 'KeyId' => 'arn:aws:kms:us-east-1:000000000000:key/synthetic']);
            }

            [$plaintext, $context] = $keys[$this->text($command['CiphertextBlob'])] ?? [null, null];

            return $plaintext !== null && $context == $command['EncryptionContext']
                ? new Result(['Plaintext' => $plaintext, 'KeyId' => $command['KeyId']])
                : new KmsException('InvalidCiphertextException', $command, ['code' => 'InvalidCiphertextException']);
        }));

        $store = new KmsKeyStore($kms, 'alias/synthetic-signing-keys');
        $pkcs12 = random_bytes(2048);
        $reference = $store->put($company->company->id, $pkcs12, 'synthetic password');

        $entry = KmsKeyStoreEntry::query()->findOrFail($reference);
        $row = implode('|', [$entry->encrypted_data_key, $entry->nonce, $entry->tag, $entry->ciphertext]);
        $this->assertStringNotContainsString('synthetic password', $row);
        $this->assertStringNotContainsString(base64_encode($pkcs12), $row);
        $this->assertSame(['company_id' => $company->company->id, 'purpose' => 'signing-certificate', 'entry_id' => $reference], array_values($keys)[0][1]);
        $this->assertSame('arn:aws:kms:us-east-1:000000000000:key/synthetic', $entry->kms_key_id);

        $bundle = $store->get($reference);
        $this->assertSame($pkcs12, $bundle->pkcs12);
        $this->assertSame('synthetic password', $bundle->password);
        $this->assertNotSame($reference, $store->put($company->company->id, $pkcs12, 'synthetic password'));
        $this->assertCount(2, $keys, 'Each certificate gets a data key of its own.');

        // A changed ciphertext fails the tag; a row moved to another company fails the context of KMS.
        $original = $entry->ciphertext;
        $entry->forceFill(['ciphertext' => base64_encode(strrev((string) base64_decode($original, true)))])->save();
        $this->assertThrows(fn () => $store->get($reference), RuntimeException::class);
        $entry->forceFill(['ciphertext' => $original, 'company_id' => $other->company->id])->save();
        $this->actingIn($other);
        $this->assertThrows(fn () => $store->get($reference), KmsException::class);

        $this->actingIn($company);
        $store->delete($reference);
        $this->actingIn($other);
        $store->delete($reference);
        $this->assertNull(KmsKeyStoreEntry::query()->find($reference));
    }

    public function test_the_s3_document_storage_writes_reads_lists_and_deletes_in_the_bucket(): void
    {
        $bucket = [];
        $calls = [];

        $s3 = new S3Client([...$this->client(function (CommandInterface $command) use (&$bucket, &$calls): Result|S3Exception {
            $calls[] = $command->getName();
            $this->assertSame('synthetic-documents', $command['Bucket']);

            $key = is_string($command['Key'] ?? null) ? $command['Key'] : '';

            return match ($command->getName()) {
                'PutObject' => (function () use ($command, $key, &$bucket): Result {
                    $body = $this->text($command['Body']);
                    $this->assertSame(base64_encode(hash('sha256', $body, true)), $command['ChecksumSHA256']);
                    $bucket[$key] = [$body, $this->text($command['ContentType'])];

                    return new Result([]);
                })(),
                'GetObject', 'HeadObject' => isset($bucket[$key]) ? new Result(['Body' => $bucket[$key][0]]) : new S3Exception('Not Found', $command, ['response' => new Response(404)]),
                'DeleteObject' => (function () use ($key, &$bucket): Result {
                    unset($bucket[$key]);

                    return new Result([]);
                })(),
                'ListObjectsV2' => new Result(['Contents' => array_map(fn (string $stored): array => ['Key' => $stored], array_values(array_filter(array_keys($bucket), fn (string $stored): bool => str_starts_with($stored, $this->text($command['Prefix']))))), 'IsTruncated' => false]),
                'DeleteObjects' => (function () use ($command, &$bucket): Result {
                    foreach ($this->list($this->list($command, 'Delete'), 'Objects') as $object) {
                        unset($bucket[$this->text($this->list([$object], 0)['Key'] ?? null)]);
                    }

                    return new Result(['Errors' => []]);
                })(),
                default => throw new InvalidArgumentException('Unexpected '.$command->getName()),
            };
        }), 'use_path_style_endpoint' => true]);

        $storage = new S3DocumentStorage($s3, 'synthetic-documents');
        $stored = $storage->put('company-a/2026/fv1.xml', '<Invoice/>');
        $storage->put('company-a/2026/fv1.pdf', '%PDF-1.7');
        $storage->put('company-b/2026/fv1.xml', '<Invoice/>');

        $this->assertSame(['company-a/2026/fv1.xml', hash('sha256', '<Invoice/>'), 10], [$stored->path, $stored->sha256, $stored->size]);
        $this->assertSame(['<Invoice/>', 'application/xml'], $bucket['company-a/2026/fv1.xml']);
        $this->assertSame('application/pdf', $bucket['company-a/2026/fv1.pdf'][1]);
        $this->assertSame('%PDF-1.7', $storage->get('company-a/2026/fv1.pdf'));
        $this->assertTrue($storage->exists('company-a/2026/fv1.xml'));
        $this->assertFalse($storage->exists('company-a/2026/none.xml'));
        $this->assertThrows(fn () => $storage->get('company-a/2026/none.xml'), RuntimeException::class);
        $this->assertThrows(fn () => $storage->put('company-a/../company-b/fv1.xml', 'x'), InvalidArgumentException::class);

        $storage->delete('company-a/2026/fv1.pdf');
        $storage->delete('company-a/2026/fv1.pdf');
        $storage->deletePrefix('company-a');
        $this->assertSame(['company-b/2026/fv1.xml'], array_keys($bucket));

        $storage->purge();
        $this->assertSame([], $bucket);
        $this->assertContains('DeleteObjects', $calls);
    }

    public function test_the_routes_of_the_topic_match_the_subscriptions_with_one_job_per_type_and_queue(): void
    {
        $subscribers = app(OutboxSubscribers::class);

        $this->assertSame(OutboxRoutesCommand::json($subscribers), file_get_contents(resource_path(OutboxRoutesCommand::FILE)), 'resources/outbox/routes.json is out of date: php artisan outbox:routes --write');

        foreach ($subscribers->routes() as $channel => $types) {
            foreach ($types as $type) {
                $this->assertCount(1, $subscribers->jobsFor($this->event($type), $channel), "{$type} on {$channel}");
            }
        }
    }

    public function test_the_rate_limit_of_api_keys_counts_in_the_store_of_the_rate_limiters(): void
    {
        Carbon::setTestNow('2026-11-20 10:00:00');
        $limiter = new CacheWindowLimiter(new RateLimiter(new Repository(new ArrayStore)));

        $counts = array_map(fn (): array => (array) $limiter->hit('api-key-rate:synthetic', 2, 60), range(1, 3));
        $this->assertSame([true, true, false], array_column($counts, 'allowed'));
        $this->assertSame([1, 0, 0], array_column($counts, 'remaining'));
        $this->assertSame(Carbon::now()->getTimestamp() + 60, $counts[0]['resetsAt']);

        Carbon::setTestNow('2026-11-20 10:01:01');
        $this->assertTrue($limiter->hit('api-key-rate:synthetic', 2, 60)->allowed, 'A new window starts after the last one ended.');
    }

    public function test_the_secret_of_the_application_is_read_as_environment_settings(): void
    {
        $key = Str::random(32);
        $answers = [json_encode(['APP_KEY' => $key, 'DB_PASSWORD' => 'dian', 'PORT' => 5]), '{"lower":"no"}', 'not json'];
        $client = new SecretsManagerClient($this->client(function () use (&$answers): Result {
            return new Result(['SecretString' => array_shift($answers)]);
        }));

        $secrets = new SecretsManagerSecrets($client);
        $this->assertSame(['APP_KEY' => $key, 'DB_PASSWORD' => 'dian', 'PORT' => '5'], $secrets->read('dian-einvoice/local/app'));
        $this->assertThrows(fn () => $secrets->read('dian-einvoice/local/app'), RuntimeException::class);
        $this->assertThrows(fn () => $secrets->read('dian-einvoice/local/app'), RuntimeException::class);
    }

    /**
     * An outbox event as the relay reads it, never saved.
     *
     * @param  array<string, mixed>  $payload
     */
    private function event(string $type, array $payload = [], ?string $availableAt = null): OutboxEvent
    {
        return (new OutboxEvent)->newFromBuilder([
            'id' => Str::lower((string) Str::ulid()),
            'company_id' => Str::lower((string) Str::ulid()),
            'aggregate_type' => 'document',
            'aggregate_id' => Str::lower((string) Str::ulid()),
            'type' => $type,
            'payload' => json_encode($payload),
            'created_at' => '2026-11-20 09:59:00',
            'available_at' => $availableAt,
            'published_at' => null,
        ]);
    }

    /**
     * The SQS message the topic delivers for an event (raw delivery).
     *
     * @return array<string, mixed>
     */
    private function message(OutboxEvent $event, string $handle, int $receives = 1): array
    {
        $entry = OutboxMessage::entry($event);

        return ['MessageId' => $handle, 'ReceiptHandle' => $handle, 'Body' => $entry['Message'], 'MessageAttributes' => $entry['MessageAttributes'], 'Attributes' => ['ApproximateReceiveCount' => (string) $receives]];
    }

    private function commandOf(mixed $job): string
    {
        $this->assertInstanceOf(SqsEventJob::class, $job);
        $payload = $job->payload();
        $this->assertIsArray($payload['data'] ?? null);

        return $this->text($payload['data']['commandName'] ?? null);
    }

    /**
     * An array inside the arguments of a call or another array.
     *
     * @param  array<array-key, mixed>|CommandInterface<string, mixed>  $container
     * @return array<array-key, mixed>
     */
    private function list(array|CommandInterface $container, string|int $key): array
    {
        $value = $container[$key] ?? null;
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * Arguments of an AWS client whose every call the handler answers from memory.
     *
     * @param  callable(CommandInterface<string, mixed>): (Result<string, mixed>|Throwable)  $answer
     * @return array<string, mixed>
     */
    private function client(callable $answer): array
    {
        return [
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => function (CommandInterface $command) use ($answer): PromiseInterface {
                $result = $answer($command);

                return $result instanceof Throwable ? Create::rejectionFor($result) : Create::promiseFor($result);
            },
        ];
    }
}
