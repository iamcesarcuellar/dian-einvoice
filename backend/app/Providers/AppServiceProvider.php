<?php

namespace App\Providers;

use App\Http\PublicOrigin;
use App\Support\ApiDocs\ApiDocumentation;
use App\Support\Aws\AwsSdk;
use App\Support\Cloud\CloudCheckCommand;
use App\Support\Cloud\CloudDrillCommand;
use App\Support\Cloud\EmulatedCloud;
use App\Support\Database\ApplicationIdentityCommand;
use App\Support\Database\ConnectionsCommand;
use App\Support\Database\MigrationsCheckCommand;
use App\Support\Database\RefreshingMySqlConnector;
use App\Support\Health\HealthCheckCommand;
use App\Support\Health\Heartbeat;
use App\Support\Keys\KeyStore;
use App\Support\Keys\KmsKeyStore;
use App\Support\Keys\LocalKeyStore;
use App\Support\Observability\ObservabilityHooks;
use App\Support\Outbox\OutboxFastPath;
use App\Support\Outbox\OutboxPublisher;
use App\Support\Outbox\OutboxRoutesCommand;
use App\Support\Outbox\OutboxStatusCommand;
use App\Support\Outbox\OutboxSubscribers;
use App\Support\Outbox\PruneOutboxEvents;
use App\Support\Outbox\PublishPendingCommand;
use App\Support\Outbox\QueueOutboxPublisher;
use App\Support\Outbox\RelayCommand;
use App\Support\Outbox\SnsOutboxPublisher;
use App\Support\Outbox\Sqs\SqsEventsConnector;
use App\Support\Outbox\SweepCommand;
use App\Support\Outbox\Sweeper;
use App\Support\Retention\PruneCommand;
use App\Support\Retention\PruneFailedJobs;
use App\Support\Retention\Pruner;
use App\Support\Retention\ScheduledPruning;
use App\Support\Secrets\DatabaseCredentials;
use App\Support\Secrets\SecretsManagerSecrets;
use App\Support\Storage\DocumentStorage;
use App\Support\Storage\LocalDocumentStorage;
use App\Support\Storage\S3DocumentStorage;
use Aws\Sdk;
use Bref\LaravelBridge\BrefServiceProvider;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Inside AWS Lambda (the functions of the emulated cloud and of production), Bref's Laravel bridge:
        // storage, compiled views and the file cache in /tmp (the rest of the file system is read-only),
        // logs to stderr for CloudWatch, the failed jobs of the consumer functions in failed_jobs. Its
        // package is not discovered, so nothing of it touches the local stack.
        if (self::runningInLambda()) {
            $this->app->register(BrefServiceProvider::class);
        }

        // The AWS services of the emulated cloud and of production, with the shared settings of config/aws.php.
        $this->app->singleton(Sdk::class, fn (Application $app): Sdk => AwsSdk::make($this->aws()));

        // Each port gets the adapter config/adapters.php names: the local one by default, the AWS one in the
        // emulated cloud. The queue, the cache, the sessions and email are Laravel's own ports.
        $this->app->singleton(DocumentStorage::class, fn (Application $app): DocumentStorage => match ($this->adapter('document_storage')) {
            'local' => new LocalDocumentStorage,
            's3' => new S3DocumentStorage($app->make(Sdk::class)->createS3(), $this->resource('documents_bucket', 'DOCUMENTS_BUCKET')),
            default => throw $this->unknown('document_storage'),
        });

        $this->app->singleton(KeyStore::class, fn (Application $app): KeyStore => match ($this->adapter('key_store')) {
            'local' => new LocalKeyStore,
            'kms' => new KmsKeyStore($app->make(Sdk::class)->createKms(), $this->resource('signing_key', 'SIGNING_KEY_ID')),
            default => throw $this->unknown('key_store'),
        });

        $this->app->singleton(OutboxPublisher::class, fn (Application $app): OutboxPublisher => match ($this->adapter('outbox_publisher')) {
            'queue' => new QueueOutboxPublisher($app->make(OutboxSubscribers::class), $app->make(Dispatcher::class)),
            'sns' => new SnsOutboxPublisher($app->make(Sdk::class)->createSns(), $this->resource('events_topic', 'EVENTS_TOPIC_ARN')),
            default => throw $this->unknown('outbox_publisher'),
        });

        // MySQL connections whose credentials come from a secret read it again, once, when the database refuses
        // them, as after a rotation (the functions read their secrets once per cold start).
        $this->app->bind('db.connector.mysql', fn (Application $app): RefreshingMySqlConnector => new RefreshingMySqlConnector(
            new DatabaseCredentials(fn (): SecretsManagerSecrets => new SecretsManagerSecrets($app->make(Sdk::class)->createSecretsManager())),
        ));

        // What the local AWS emulator holds, for make cloud-check and make cloud-demo.
        $this->app->singleton(EmulatedCloud::class, fn (Application $app): EmulatedCloud => new EmulatedCloud($app->make(Sdk::class), $this->aws()));

        // Modules subscribe their jobs to outbox event types in their providers.
        $this->app->singleton(OutboxSubscribers::class);

        // The events a process records, kept until its transaction commits (config outbox.publish_after_commit).
        $this->app->singleton(OutboxFastPath::class);

        // Retention: modules tag their pruners; the sweepers run them once an hour (ScheduledPruning).
        $this->app->tag([PruneOutboxEvents::class, PruneFailedJobs::class], Pruner::class);
        $this->app->tag([ScheduledPruning::class], Sweeper::class);
    }

    public function boot(): void
    {
        // Outside production, fail on lazy loading, unknown attributes and silently discarded fills.
        Model::shouldBeStrict(! $this->app->isProduction());

        // The public API reference at /docs/api, and openapi.json through make openapi.
        ApiDocumentation::configure();

        // Behind the edge (config/edge.php) the application sees the host of API Gateway, which refuses whoever does
        // not come through the edge: every URL it generates, the links of the pages of a list and the servers of
        // the API reference included, takes the public origin of the platform, APP_URL.
        if (config()->boolean('edge.behind')) {
            PublicOrigin::apply(config()->string('app.url'));
        }

        // The queue workers beat while they loop, for the healthcheck of their containers.
        Event::listen(Looping::class, fn () => Heartbeat::beat('worker'));

        // The journeys that begin outside a request (a command, a scheduled run), and the lines of the jobs and of
        // the scheduled tasks in the functions (docs/observability.md).
        ObservabilityHooks::register($this->app->make('events'));

        // The SQS queues of the emulated cloud (connection sqs), and the tables of DynamoDB of the cache, the
        // sessions and the rate limits, all with the shared AWS settings (Laravel's own DynamoDB store has
        // no timeouts).
        $this->callAfterResolving(QueueManager::class, function (QueueManager $queue): void {
            $queue->addConnector('sqs-events', fn (): SqsEventsConnector => new SqsEventsConnector($this->aws(), $this->app->make(OutboxSubscribers::class)));
        });

        $this->callAfterResolving(CacheManager::class, function (CacheManager $cache): void {
            $cache->extend('dynamodb', function (Application $app, array $config) {
                /** @var CacheManager $this */
                $table = $config['table'] ?? null;

                return $this->repository(new DynamoDbStore(
                    $app->make(Sdk::class)->createDynamoDb(),
                    is_string($table) ? $table : 'cache',
                    prefix: $this->getPrefix($config),
                    serializableClasses: $this->getSerializableClasses($config),
                ), $config);
            });
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RelayCommand::class, PublishPendingCommand::class, SweepCommand::class, HealthCheckCommand::class, PruneCommand::class, OutboxRoutesCommand::class, CloudCheckCommand::class, ApplicationIdentityCommand::class, OutboxStatusCommand::class, ConnectionsCommand::class, MigrationsCheckCommand::class, CloudDrillCommand::class]);
        }
    }

    /** Whether this process runs inside AWS Lambda, which sets LAMBDA_TASK_ROOT (the emulator too). */
    public static function runningInLambda(): bool
    {
        return isset($_SERVER['LAMBDA_TASK_ROOT']) && is_string($_SERVER['LAMBDA_TASK_ROOT']) && $_SERVER['LAMBDA_TASK_ROOT'] !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function aws(): array
    {
        return config('aws');
    }

    private function adapter(string $port): string
    {
        $adapter = config("adapters.{$port}");

        return is_string($adapter) ? $adapter : '';
    }

    private function resource(string $key, string $variable): string
    {
        $value = config("aws.{$key}");

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$variable} is not set: the AWS adapters need the resources of the CDK app (make cloud-up writes them to backend/.env.cloud).");
        }

        return $value;
    }

    private function unknown(string $port): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('Unknown adapter %s for %s in config/adapters.php.', $this->adapter($port), $port));
    }
}
