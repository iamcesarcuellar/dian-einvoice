<?php

namespace Tests\Integration\Operations;

use App\Support\Cloud\CloudCheckProbe;
use App\Support\Health\SchedulerHeartbeat;
use App\Support\Observability\Correlation;
use App\Support\Observability\JsonLogFormatter;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\Sqs\SqsEventsHandler;
use App\Support\Outbox\Sqs\SqsEventsQueue;
use Aws\CommandInterface;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\HttpResponse;
use Bref\LaravelBridge\Http\SymfonyRequestBridge;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Monolog\Handler\StreamHandler;
use Tests\IntegrationTestCase;

/**
 * The handlers of the functions with sample events of AWS Lambda (tests/Fixtures/Lambda), cheap to run:
 * a batch of SQS with a raw delivery and an envelope of SNS and partial failures, the event of a schedule,
 * and a request of API Gateway HTTP API (payload format 2.0) with cookies. The AWS SDK answers from memory;
 * make cloud-check runs the functions themselves in the local emulator.
 */
final class FunctionsTest extends IntegrationTestCase
{
    private const string CHAIN = 'http://floci:4566/000000000000/dian-einvoice-local-chain';

    /** @var list<array{string, int}> Receipt handle and visibility of each ChangeMessageVisibility. */
    private array $visibility = [];

    /** @var list<string> Receipt handles deleted. */
    private array $deleted = [];

    public function test_a_batch_of_sqs_answers_which_of_its_messages_failed_so_the_others_are_not_sent_back(): void
    {
        $queue = $this->queue();
        $marker = 'functions-test-'.Str::lower((string) Str::ulid());
        Carbon::setTestNow('2026-11-20 10:00:00');

        // The two records of the sample: an event of a raw delivery of SNS (the subscriptions of the CDK app)
        // and the same event in the notification envelope of SNS; the generation of the XML of a document
        // whose company is gone does nothing, and succeeds.
        $issued = $this->event('invoice.issued');
        $entry = OutboxMessage::entry($issued);
        [$raw, $envelope] = $this->records();
        $raw['body'] = $entry['Message'];
        $envelope['body'] = (string) json_encode([
            'Type' => 'Notification',
            'MessageId' => (string) Str::uuid(),
            'TopicArn' => 'arn:aws:sns:us-east-1:000000000000:dian-einvoice-local-document-events',
            'Message' => $entry['Message'],
            'Timestamp' => '2026-11-20T10:00:00.000Z',
            'SignatureVersion' => '1',
            'MessageAttributes' => ['type' => ['Type' => 'String', 'Value' => 'invoice.issued'], 'company_id' => ['Type' => 'String', 'Value' => $issued->company_id]],
        ], JSON_UNESCAPED_SLASHES);

        // A message no handler can read, a transmission retry due in 15 s, and a job of the application.
        $unreadable = $this->record($raw, 'unreadable', '{"not": an event', 'electronic_document.sign_due');
        $later = $this->record($raw, 'later', OutboxMessage::entry($this->event('electronic_document.transmit_due', '2026-11-20 10:00:15'))['Message'], 'electronic_document.transmit_due');
        $probe = $this->record($raw, 'probe', $queue->bodyOf(new CloudCheckProbe($marker, 0), 'chain'), null);
        $event = ['Records' => [$raw, $envelope, $unreadable, $later, $probe]];

        $answer = (new SqsEventsHandler(app()))->handle($event, $this->context(seconds: 60));

        $this->assertSame(['batchItemFailures' => [['itemIdentifier' => 'unreadable'], ['itemIdentifier' => 'later']]], $answer);
        $this->assertSame(['AQEBzWwaftRI0KuVm4tP', 'AQEBMnv8sPyQ1gJ2eQnL', 'probe-handle'], array_map(fn (string $handle): string => substr($handle, 0, 20), $this->deleted), 'The messages that succeeded are deleted, the others stay in the queue.');
        $this->assertSame([['unreadable-handle', 1], ['later-handle', 15]], $this->visibility, 'The retry waits until it is due; the unreadable message comes back after the backoff of its job, until the redrive policy moves it to the dead-letter queue.');
        $runs = Cache::get(CloudCheckProbe::runsKey($marker, 0));
        $this->assertTrue(is_numeric($runs) && (int) $runs === 1, 'The job of the application ran once.');
        $this->assertSame(['7f1c63a3-2b6c-4b5e-9a37-0d2f1a1e5c01', 'c2a0e9b4-6d1f-4f8e-8c3a-5b7d9e1f2a02', 'unreadable', 'later', 'probe'], Cache::get(CloudCheckProbe::batchKey($marker, 0)), 'A job knows the batch it runs in.');
        $this->assertSame([], SqsEventsHandler::currentBatch());
    }

    public function test_a_job_resumes_the_journey_of_its_event_and_writes_its_line_as_the_invocation(): void
    {
        $this->queue();
        $log = storage_path('logs/functions-journey.log');
        File::delete($log);
        config([
            'logging.channels.journey-test' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => $log], 'formatter' => JsonLogFormatter::class, 'level' => 'debug'],
            'logging.default' => 'journey-test',
            'observability.jobs' => true,
        ]);
        $_SERVER['LAMBDA_REQUEST_ID'] = '8c1d0e2f-3a4b-4c5d-9e6f-7a8b9c0d1e2f';
        $correlation = Correlation::generate();

        // The journey of another job is in the Context of the process: the record starts from an empty one.
        Correlation::begin();
        $raw = $this->records()[0];
        $raw['body'] = OutboxMessage::entry($this->event('invoice.issued', correlation: $correlation))['Message'];

        try {
            $answer = (new SqsEventsHandler(app()))->handle(['Records' => [$raw]], $this->context(seconds: 60));
            $lines = array_values(array_filter(explode("\n", File::exists($log) ? File::get($log) : '')));
        } finally {
            unset($_SERVER['LAMBDA_REQUEST_ID']);
            app('log')->forgetChannel('journey-test');
            File::delete($log);
        }

        $this->assertNull($answer, 'No record failed.');
        $jobs = array_values(array_filter(array_map(fn (string $line): array => (array) json_decode($line, true), $lines), fn (array $line): bool => ($line['event'] ?? null) === 'queue.job'));
        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $detail = $job['detail'] ?? null;
        $this->assertIsArray($detail);
        $this->assertSame([$correlation, '8c1d0e2f-3a4b-4c5d-9e6f-7a8b9c0d1e2f', 'system', 'success', 'GenerateXml', 'chain'], [$job['correlation_id'] ?? null, $job['request_id'] ?? null, $job['actor'] ?? null, $job['outcome'] ?? null, $detail['job'] ?? null, $detail['queue'] ?? null]);
        $this->assertIsFloat($job['duration_ms'] ?? null);
    }

    public function test_a_record_whose_job_would_not_finish_before_the_deadline_goes_back_at_once(): void
    {
        $this->queue();
        $raw = $this->records()[0];
        $raw['body'] = OutboxMessage::entry($this->event('invoice.issued'))['Message'];

        // A job may run 35 s; with 20 s left it is not started, and the message is visible again at once.
        $answer = (new SqsEventsHandler(app()))->handle(['Records' => [$raw]], $this->context(seconds: 20));

        $this->assertSame(['batchItemFailures' => [['itemIdentifier' => '7f1c63a3-2b6c-4b5e-9a37-0d2f1a1e5c01']]], $answer);
        $this->assertCount(1, $this->visibility);
        $this->assertSame(0, $this->visibility[0][1]);
        $this->assertSame([], $this->deleted);
    }

    public function test_the_scheduled_event_runs_the_scheduler_whose_tasks_run_once_however_often_it_fires(): void
    {
        // The Input of the schedule is the command line of php artisan, as the handler of the function reads it.
        $command = json_decode((string) file_get_contents(base_path('tests/Fixtures/Lambda/scheduled.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('schedule:run', $command);

        // The schedule of bootstrap/app.php, which the console kernel defines when it starts. Every task runs in the
        // process of the scheduler, which logs what it does as JSON: none prints into the log of the function.
        $this->assertSame(0, Artisan::call('schedule:list'));
        $events = collect(app(Schedule::class)->events());
        $this->assertSame([], $events->reject(fn ($event): bool => $event instanceof CallbackEvent)->all(), 'No task runs as a process of its own.');
        $sweep = $events->first(fn ($event): bool => $event instanceof CallbackEvent && $event->description === 'outbox-sweep');
        $heartbeat = $events->first(fn ($event): bool => $event instanceof CallbackEvent && $event->description === 'scheduler-heartbeat');
        $this->assertNotNull($sweep);
        $this->assertNotNull($heartbeat);

        foreach ([$sweep, $heartbeat] as $task) {
            $this->assertSame('* * * * *', $task->expression);
            $this->assertTrue($task->onOneServer, 'A schedule that fires twice runs the task once.');
            $this->assertFalse($task->runInBackground, 'Nothing outlives the invocation: a function is frozen as soon as it answers.');
        }

        $this->assertTrue($sweep->withoutOverlapping, 'A run that takes longer than a minute is not overlapped.');

        // Twice in the same minute, as a schedule that fires twice: the heartbeat is noted once.
        Cache::forget(SchedulerHeartbeat::KEY);
        Carbon::setTestNow(Carbon::now()->startOfMinute()->addSeconds(5));
        $this->assertSame(0, Artisan::call($command));
        $first = SchedulerHeartbeat::lastRun();
        $this->assertSame(Carbon::now()->getTimestamp(), $first);

        // Each invocation is a process of its own, with a schedule of its own.
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        app()->forgetInstance(Schedule::class);
        $this->assertSame(0, Artisan::call($command));
        $this->assertStringContainsString('Skipping [scheduler-heartbeat]', Artisan::output());
        $this->assertSame($first, SchedulerHeartbeat::lastRun());
    }

    public function test_a_request_of_api_gateway_v2_gets_its_cookies_and_its_answer_goes_back_with_them(): void
    {
        $event = $this->sample('http-api-v2.json');
        $request = SymfonyRequestBridge::convertRequest(new HttpRequestEvent($event), $this->context(seconds: 28));

        // PHP-FPM of the function sees every request come from the Bref runtime, as 127.0.0.1.
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $this->assertSame('dark', $request->cookies->get('theme'));
        $this->assertSame('eyJpdiI6InN5bnRoZXRpYyJ9', $request->cookies->get('laravel_session'));

        config(['trustedproxy.proxies' => '127.0.0.1']);
        $response = app(Kernel::class)->handle(Request::createFromBase($request));
        $this->assertSame(204, $response->getStatusCode());

        // The cookies of the answer go to API Gateway as the cookies of the payload, never as a header.
        putenv('BREF_BINARY_RESPONSES=1');

        $headers = array_map(fn (array $values): array => array_values(array_filter($values, 'is_string')), $response->headers->all());

        try {
            $answer = (new HttpResponse((string) $response->getContent(), $headers, $response->getStatusCode()))->toApiGatewayFormatV2();
        } finally {
            putenv('BREF_BINARY_RESPONSES');
        }

        $this->assertSame(204, $answer['statusCode']);
        $this->assertTrue($answer['isBase64Encoded']);
        $this->assertIsArray($answer['cookies']);
        $cookies = implode("\n", array_map(fn (mixed $cookie): string => $this->text($cookie), $answer['cookies']));
        $this->assertStringContainsString('XSRF-TOKEN=', $cookies);
        $this->assertStringContainsString(config()->string('session.cookie').'=', $cookies);
        $this->assertArrayNotHasKey('Set-Cookie', (array) $answer['headers']);
    }

    public function test_behind_api_gateway_the_address_of_the_client_is_the_one_api_gateway_saw(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);
        $ip = null;

        // The client sends an X-Forwarded-For of its own; API Gateway appends the address it saw.
        $request = Request::create('/api/v1/health', server: ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.9', 'HTTP_X_FORWARDED_HOST' => 'chosen.example']);
        app(TrustProxies::class)->handle($request, function (Request $request) use (&$ip) {
            $ip = $request->ip();

            return response('');
        });

        $this->assertSame('203.0.113.9', $ip);
        $this->assertNotSame('chosen.example', $request->getHost(), 'A client cannot choose the host of the URLs.');
    }

    /**
     * The chain queue of the stage local, with the AWS SDK answering from memory, as the connection sqs.
     */
    private function queue(): SqsEventsQueue
    {
        $sqs = new SqsClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => false,
            'handler' => function (CommandInterface $command): PromiseInterface {
                return Create::promiseFor(match ($command->getName()) {
                    'GetQueueAttributes' => new Result(['Attributes' => ['RedrivePolicy' => '{"deadLetterTargetArn":"arn:aws:sqs:us-east-1:000000000000:dian-einvoice-local-chain-dlq","maxReceiveCount":10}']]),
                    'ChangeMessageVisibility' => $this->noted($this->visibility, [$this->text($command['ReceiptHandle']), is_numeric($command['VisibilityTimeout']) ? (int) $command['VisibilityTimeout'] : -1]),
                    'DeleteMessage' => $this->noted($this->deleted, $this->text($command['ReceiptHandle'])),
                    default => throw new InvalidArgumentException('Unexpected '.$command->getName()),
                });
            },
        ]);

        $queue = new SqsEventsQueue($sqs, ['chain' => self::CHAIN, 'webhooks' => 'http://floci:4566/000000000000/dian-einvoice-local-webhooks'], app(OutboxSubscribers::class));

        app(QueueManager::class)->addConnector('sqs-events', fn (): ConnectorInterface => new class($queue) implements ConnectorInterface
        {
            public function __construct(private readonly SqsEventsQueue $queue) {}

            /**
             * @param  array<string, mixed>  $config
             */
            public function connect(array $config): SqsEventsQueue
            {
                return $this->queue;
            }
        });

        $resolved = app(QueueManager::class)->connection('sqs');
        $this->assertSame($queue, $resolved);

        return $queue;
    }

    /**
     * @template T
     *
     * @param  list<T>  $list
     * @param  T  $value
     * @return Result<string, mixed>
     */
    private function noted(array &$list, mixed $value): Result
    {
        $list[] = $value;

        return new Result([]);
    }

    /**
     * A record like another of the sample, with its own id, body and type attribute.
     *
     * @param  array<string, mixed>  $like
     * @return array<string, mixed>
     */
    private function record(array $like, string $id, string $body, ?string $type): array
    {
        $like['messageId'] = $id;
        $like['receiptHandle'] = "{$id}-handle";
        $like['body'] = $body;
        $like['messageAttributes'] = $type === null ? [] : ['type' => ['stringValue' => $type, 'stringListValues' => [], 'binaryListValues' => [], 'dataType' => 'String']];

        return $like;
    }

    /** An outbox event of a company that does not exist, as the relay publishes it, never saved. */
    private function event(string $type, ?string $availableAt = null, ?string $correlation = null): OutboxEvent
    {
        return (new OutboxEvent)->newFromBuilder([
            'id' => Str::lower((string) Str::ulid()),
            'company_id' => Str::lower((string) Str::ulid()),
            'aggregate_type' => 'document',
            'aggregate_id' => Str::lower((string) Str::ulid()),
            'type' => $type,
            'correlation_id' => $correlation,
            'payload' => json_encode(['document_id' => Str::lower((string) Str::ulid()), 'run' => 1]),
            'created_at' => '2026-11-20 09:59:00',
            'available_at' => $availableAt,
            'published_at' => null,
        ]);
    }

    private function context(int $seconds): Context
    {
        return new Context('8c1d0e2f-3a4b-4c5d-9e6f-7a8b9c0d1e2f', (int) (microtime(true) * 1000) + $seconds * 1000, 'arn:aws:lambda:us-east-1:000000000000:function:dian-einvoice-local-chain', 'Root=1-6734c1f0-0123456789abcdef01234567');
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(string $file): array
    {
        $sample = json_decode((string) file_get_contents(base_path("tests/Fixtures/Lambda/{$file}")), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($sample);

        /** @var array<string, mixed> $sample */
        return $sample;
    }

    /**
     * The two records of the sample batch of SQS.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function records(): array
    {
        $records = $this->sample('sqs-batch.json')['Records'] ?? null;
        $this->assertIsArray($records);
        $this->assertIsArray($records[0] ?? null);
        $this->assertIsArray($records[1] ?? null);

        /** @var array{0: array<string, mixed>, 1: array<string, mixed>} $records */
        return $records;
    }
}
