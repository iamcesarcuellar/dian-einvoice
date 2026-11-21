<?php

namespace Tests\Integration\Operations;

use App\Support\Lambda\ArtisanHandler;
use App\Support\Lambda\CommandFailed;
use App\Support\Observability\Correlation;
use App\Support\Observability\JsonLogFormatter;
use App\Support\Observability\LogEvent;
use App\Support\Observability\LogSchema;
use App\Support\Observability\Redactor;
use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\QueueOutboxPublisher;
use Bref\Context\Context;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Context as LogContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use RuntimeException;
use Tests\IntegrationTestCase;
use Tests\Support\SecretShapes;

/**
 * The observability of the backend (Infrastructure task 5): the structured log line of the functions holds exactly
 * the fields of resources/observability/log-schema.json, masks what looks like a secret or personal data, and
 * drops what the schema does not name; the API answers the correlation id of every request and keeps the one a
 * client sends only when it has the form of one; the journey travels with the outbox events, their messages and
 * their jobs; and the handler of the artisan functions answers what a command prints and logs only JSON.
 */
final class ObservabilityTest extends IntegrationTestCase
{
    public function test_the_events_of_the_backend_are_events_of_the_schema(): void
    {
        $schema = array_keys(LogSchema::events());
        $backend = array_map(fn (LogEvent $event): string => $event->value, LogEvent::cases());

        $this->assertSame([], array_values(array_diff($backend, $schema)), 'Events the backend writes that the schema does not list.');
        $typescript = array_values(array_diff($schema, $backend));
        sort($typescript);
        $this->assertSame(['alarm.notification', 'edge.refused', 'edge.secret'], $typescript, 'Events of the schema only the TypeScript functions write.');

        foreach (LogSchema::events() as $event => $meaning) {
            $this->assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $event);
            $this->assertNotSame('', $meaning, "{$event} says what it means.");
        }
    }

    public function test_every_line_holds_exactly_the_fields_of_the_schema_in_their_order(): void
    {
        $fields = LogSchema::fields();
        $required = array_keys(array_filter($fields, fn (array $field): bool => ! $field['optional']));

        // A line of the framework, without anything of the schema.
        $bare = JsonLogFormatter::line($this->record('Something happened.'));
        $this->assertSame($required, array_keys($bare));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $this->text($bare['time']));
        $this->assertSame('info', $bare['level']);
        $this->assertSame('Something happened.', $bare['message']);

        foreach ($required as $name) {
            if (! $fields[$name]['nullable']) {
                $this->assertNotNull($bare[$name], "{$name} is never null.");
            }
        }

        // A line of an event: the fields of its context and of Laravel's Context, the detail the schema names, the
        // error with its trace cut; anything else is dropped, and an id that is not one is not believed.
        $correlation = Correlation::generate();
        $document = Str::lower((string) Str::ulid());
        $exception = new RuntimeException('It failed for owner@example.com with password=hunter2-secret.');
        $line = JsonLogFormatter::line($this->record('The step failed.', Level::Error, [
            'event' => LogEvent::QueueJob->value,
            'outcome' => 'failure',
            'duration_ms' => 12.345,
            'document_id' => $document,
            'exception' => $exception,
            'detail' => ['job' => 'TransmitDocument', 'attempt' => '3', 'email' => 'owner@example.com', 'status' => 'not a number'],
            'email' => 'owner@example.com',
        ], [
            'correlation_id' => $correlation,
            'request_id' => 'req-1234567890',
            'company_id' => 'not an id',
            'actor' => 'user:'.Str::lower((string) Str::ulid()),
        ]));

        $this->assertSame([...$required, 'error', 'detail'], array_keys($line));
        $this->assertSame([$correlation, 'req-1234567890', null, $document, 'queue.job', 12.3, 'failure', 'error'], [$line['correlation_id'], $line['request_id'], $line['company_id'], $line['document_id'], $line['event'], $line['duration_ms'], $line['outcome'], $line['level']]);
        $this->assertMatchesRegularExpression('/^user:[0-9a-z]{26}$/', $this->text($line['actor']));
        $this->assertSame(['job' => 'TransmitDocument', 'attempt' => 3], $line['detail'], 'Detail keeps the keys of the schema, of their type.');
        $error = $line['error'];
        $this->assertIsArray($error);
        $this->assertSame(RuntimeException::class, $error['class']);
        $this->assertSame('It failed for [email] with password=[redacted]', $error['message']);
        $this->assertIsArray($error['trace']);
        $this->assertLessThanOrEqual(8, count($error['trace']));
        $this->assertStringStartsWith('tests/Integration/Operations/ObservabilityTest.php:', $this->text($error['trace'][0] ?? null), 'Frames name files from the root of the application.');
        $this->assertStringNotContainsString('hunter2', JsonLogFormatter::encode($line));
        $this->assertStringNotContainsString('owner@example.com', JsonLogFormatter::encode($line));

        // An event or an outcome the schema does not know is null.
        $unknown = JsonLogFormatter::line($this->record('x', Level::Info, ['event' => 'made.up', 'outcome' => 'perhaps']));
        $this->assertNull($unknown['event']);
        $this->assertNull($unknown['outcome']);

        // One line each, whatever the message holds.
        $formatted = (new JsonLogFormatter)->format($this->record("Two\nlines"));
        $this->assertSame(1, substr_count($formatted, "\n"));
        $this->assertStringEndsWith("\n", $formatted);
    }

    public function test_the_redactor_masks_secrets_and_personal_data_and_keeps_internal_ids(): void
    {
        $ulid = Str::lower((string) Str::ulid());
        $uuid = (string) Str::uuid();
        // The values with the form of a secret are built at run time, never written here (SecretShapes).
        $cases = [
            'Sent to ana.gomez@example.com.' => 'Sent to [email].',
            'NIT 900123456-7 and phone 3001234567.' => 'NIT [number] and phone [number].',
            'Key '.SecretShapes::apiKey().' refused.' => 'Key [api-key] refused.',
            'Secret '.SecretShapes::webhookSecret().' rotated.' => 'Secret [secret] rotated.',
            'APP_KEY '.SecretShapes::appKey().' read.' => 'APP_KEY [secret] read.',
            'Authorization: Bearer abc.def.ghi' => 'Authorization: [redacted] [token]',
            'token '.SecretShapes::jwt().' here' => 'token [token] here',
            'pwd='.SecretShapes::password().' next' => 'pwd=[redacted] next',
            'Key '.SecretShapes::awsAccessKeyId().' leaked.' => 'Key [aws-key] leaked.',
            'The secret value '.SecretShapes::opaque().' was not logged.' => 'The secret value [redacted] was not logged.',
            SecretShapes::privateKeyPem() => '[pem]',
            'Answer <?xml version="1.0"?><Invoice><cbc:ID>SETP1</cbc:ID></Invoice>' => 'Answer [document]',
            'From 172.24.0.9 to the database.' => 'From [ip] to the database.',
            "Document {$ulid} of request {$uuid}, 3 lines, 45000 pesos, version 8.4.8." => "Document {$ulid} of request {$uuid}, 3 lines, 45000 pesos, version 8.4.8.",
            'App\\Modules\\Dian\\Pipeline\\TransmitDocument at api/v1/invoices/{document}/issue' => 'App\\Modules\\Dian\\Pipeline\\TransmitDocument at api/v1/invoices/{document}/issue',
            'dian-einvoice-local-webhooks-dlq and dian-einvoice/local/database/application' => 'dian-einvoice-local-webhooks-dlq and dian-einvoice/local/database/application',
        ];

        foreach ($cases as $given => $expected) {
            $this->assertSame($expected, Redactor::text($given, 500), $given);
        }

        $this->assertSame('abcd…', Redactor::text('abcdefgh', 5), 'Cut to the limit.');
    }

    public function test_the_api_answers_the_correlation_id_of_every_request_and_keeps_a_valid_one_only(): void
    {
        $header = LogSchema::correlationHeader();

        $new = $this->getJson('/api/v1/health')->assertOk()->headers->get($header);
        $this->assertIsString($new);
        $this->assertTrue(Correlation::valid($new));

        $chosen = Correlation::generate();
        $this->assertSame($chosen, $this->withHeaders([$header => strtoupper($chosen)])->getJson('/api/v1/health')->headers->get($header), 'A valid id is kept, in lower case.');

        foreach (['not-an-id', str_repeat('a', 300), '01J; DROP TABLE', ''] as $made) {
            $answered = $this->withHeaders([$header => $made])->getJson('/api/v1/health')->headers->get($header);
            $this->assertIsString($answered);
            $this->assertTrue(Correlation::valid($answered) && $answered !== $made, "{$made} is replaced.");
        }

        // Errors carry it too.
        $this->assertTrue(Correlation::valid((string) $this->getJson('/api/v1/nothing-lives-here')->assertNotFound()->headers->get($header)));
    }

    public function test_the_api_reference_documents_the_correlation_id_of_every_request_and_problem(): void
    {
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        $this->assertIsArray($document);
        $header = LogSchema::correlationHeader();
        $this->assertIsArray($document['info'] ?? null);
        $this->assertStringContainsString("## Correlation\n\nEvery answer carries `{$header}`", $this->text($document['info']['description'] ?? null));
        $this->assertIsArray($document['paths'] ?? null);
        $operations = 0;

        foreach ($document['paths'] as $path => $methods) {
            $this->assertIsArray($methods);

            foreach ($methods as $method => $operation) {
                if (! is_array($operation) || ! isset($operation['responses'])) {
                    continue;
                }

                $operations++;
                $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];
                $this->assertNotEmpty(array_filter($parameters, fn (mixed $parameter): bool => is_array($parameter) && ($parameter['name'] ?? null) === $header && ($parameter['in'] ?? null) === 'header' && ($parameter['required'] ?? false) === false), "{$method} {$path} documents the {$header} it may send.");
                $this->assertIsArray($operation['responses']);

                foreach ($operation['responses'] as $status => $response) {
                    $this->assertIsArray($response);
                    $resolved = isset($response['$ref']) ? $this->responseComponent($document, $this->text($response['$ref'])) : $response;
                    $this->assertArrayHasKey($header, is_array($resolved['headers'] ?? null) ? $resolved['headers'] : [], "{$method} {$path} {$status} documents the {$header} it answers.");

                    $content = is_array($resolved['content'] ?? null) ? $resolved['content'] : [];

                    if (isset($content['application/problem+json'])) {
                        $this->assertStringContainsString('"correlation_id"', (string) json_encode($content['application/problem+json']), "{$method} {$path} {$status}: the problem carries correlation_id.");
                    }
                }
            }
        }

        $this->assertGreaterThan(50, $operations);
    }

    public function test_the_journey_travels_with_the_outbox_events_their_messages_and_the_jobs_they_start(): void
    {
        $company = $this->company('Synthetic Observability Company');
        $correlation = Correlation::generate();

        // The journey of a request: the outbox events it records carry its id.
        $draft = $this->draftInvoice($company);
        $this->signedIn($company->user, $company)->withHeaders([LogSchema::correlationHeader() => $correlation])->postJson("/api/v1/invoices/{$draft->id}/issue")->assertSuccessful();
        $event = OutboxEvent::query()->where('aggregate_id', $draft->id)->orderBy('id')->firstOrFail();
        $this->assertSame($correlation, $event->correlationId());

        // In the message of SNS and SQS: the body and an attribute; read back from a message of SQS.
        $entry = OutboxMessage::entry($event);
        $this->assertSame($correlation, $entry['MessageAttributes'][OutboxMessage::CORRELATION]['StringValue']);
        $body = json_decode($entry['Message'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($body);
        $this->assertSame($correlation, $body['correlation_id']);
        $read = OutboxMessage::fromSqs(['MessageId' => 'm', 'Body' => $entry['Message'], 'MessageAttributes' => $entry['MessageAttributes']]);
        $this->assertSame($correlation, $read?->correlationId());

        // A message without one in its body still has it as an attribute; one that is not an id is dropped.
        unset($body['correlation_id']);
        $this->assertSame($correlation, OutboxMessage::fromSqs(['MessageId' => 'm', 'Body' => json_encode($body), 'MessageAttributes' => $entry['MessageAttributes']])?->correlationId());
        $this->assertNull(OutboxMessage::fromSqs(['MessageId' => 'm', 'Body' => json_encode([...$body, 'correlation_id' => 'not an id']), 'MessageAttributes' => [OutboxMessage::TYPE => $entry['MessageAttributes'][OutboxMessage::TYPE]]])?->correlationId());

        // Locally the relay dispatches the jobs of the event inside its journey: their payloads carry it to the
        // worker in Laravel's Context, whatever the journey of the relay itself.
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => 'observability-test']);
        Redis::connection()->del('queues:observability-test');
        Correlation::begin();

        try {
            app(QueueOutboxPublisher::class)->publish([$event]);
            $payloads = Redis::connection()->lrange('queues:observability-test', 0, -1);
        } finally {
            Redis::connection()->del('queues:observability-test');
            config(['queue.default' => 'sync']);
        }

        $this->assertIsArray($payloads);
        $this->assertNotEmpty($payloads);

        foreach ($payloads as $payload) {
            $decoded = json_decode($this->text($payload), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $context = $decoded['illuminate:log:context'] ?? null;
            $this->assertIsArray($context);
            $this->assertIsArray($context['data'] ?? null);
            $this->assertSame($correlation, unserialize($this->text($context['data']['correlation_id'] ?? null)), 'The job resumes the journey of its event.');
        }

        $this->assertNotSame($correlation, Correlation::current(), 'The relay keeps its own journey.');
    }

    public function test_the_handler_of_the_artisan_functions_answers_what_a_command_prints_and_logs_only_json(): void
    {
        $log = storage_path('logs/observability-test.log');
        File::delete($log);
        config(['logging.channels.observability-test' => ['driver' => 'monolog', 'handler' => StreamHandler::class, 'with' => ['stream' => $log], 'formatter' => JsonLogFormatter::class, 'level' => 'debug']]);
        config(['logging.default' => 'observability-test']);
        $_SERVER['LAMBDA_REQUEST_ID'] = '8c1d0e2f-3a4b-4c5d-9e6f-7a8b9c0d1e2f';

        try {
            $answer = (new ArtisanHandler)->handle('--version', $this->lambda());
            $this->assertSame(0, $answer['exitCode']);
            $this->assertStringContainsString('Laravel Framework', $answer['output'], 'What the command printed is the answer of the invocation.');

            try {
                (new ArtisanHandler)->handle('no-such-command --with arguments@example.com', $this->lambda());
                $this->fail('A command that fails fails the invocation.');
            } catch (CommandFailed $failed) {
                $this->assertStringContainsString('no-such-command', $failed->getMessage());
            }

            $lines = array_values(array_filter(explode("\n", File::exists($log) ? File::get($log) : '')));
        } finally {
            unset($_SERVER['LAMBDA_REQUEST_ID']);
            app('log')->forgetChannel('observability-test');
            File::delete($log);
        }

        $commands = array_values(array_filter(array_map(fn (string $line): array => (array) json_decode($line, true), $lines), fn (array $line): bool => ($line['event'] ?? null) === 'console.command'));
        $this->assertCount(2, $commands);
        $this->assertSame([['--version', 0, 'success'], ['no-such-command', 1, 'failure']], array_map(function (array $line): array {
            $detail = $line['detail'] ?? null;
            $this->assertIsArray($detail);

            return [$detail['command'] ?? null, $detail['exit_code'] ?? null, $line['outcome'] ?? null];
        }, $commands));

        foreach ($commands as $line) {
            $this->assertTrue(Correlation::valid($this->text($line['correlation_id'] ?? null)), 'Each invocation begins a journey.');
            $this->assertSame('8c1d0e2f-3a4b-4c5d-9e6f-7a8b9c0d1e2f', $line['request_id']);
            $this->assertSame('system', $line['actor']);
        }

        $this->assertStringNotContainsString('arguments@example.com', implode("\n", $lines), 'The arguments of a command are never logged.');

        // A line a process printed outside the logger becomes the message of a line of the schema.
        $wrapped = json_decode(ArtisanHandler::wrapped('PHP Warning:  Undefined variable $token for ana@example.com'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($wrapped);
        $this->assertSame(array_keys(array_filter(LogSchema::fields(), fn (array $field): bool => ! $field['optional'])), array_keys($wrapped));
        $this->assertSame(['runtime.output', 'warning'], [$wrapped['event'], $wrapped['level']]);
        $this->assertStringNotContainsString('ana@example.com', $this->text($wrapped['message']));
    }

    public function test_an_id_in_the_context_that_is_not_one_is_not_believed(): void
    {
        LogContext::add('correlation_id', 'not-an-id');
        Log::info('A line with a context that is not believed.');
        $this->assertNull(Correlation::current());
        $this->assertTrue(Correlation::valid(Correlation::ensure()));
    }

    /**
     * A response of the components of an OpenAPI document, by its reference (#/components/responses/Name).
     *
     * @param  array<mixed>  $document
     * @return array<mixed>
     */
    private function responseComponent(array $document, string $reference): array
    {
        $name = substr($reference, strlen('#/components/responses/'));
        $components = $document['components'] ?? null;
        $this->assertIsArray($components);
        $this->assertIsArray($components['responses'] ?? null);
        $response = $components['responses'][$name] ?? null;
        $this->assertIsArray($response, $reference);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    private function record(string $message, Level $level = Level::Info, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(new DateTimeImmutable('2026-11-20 15:04:05.123456', new DateTimeZone('America/Bogota')), 'test', $level, $message, $context, $extra);
    }

    private function lambda(): Context
    {
        return new Context('8c1d0e2f-3a4b-4c5d-9e6f-7a8b9c0d1e2f', (int) (microtime(true) * 1000) + 60_000, 'arn:aws:lambda:us-east-1:000000000000:function:dian-einvoice-local-console', 'Root=1-6734c1f0-0123456789abcdef01234567');
    }
}
