<?php

namespace App\Support\Outbox\Sqs;

use Bref\Context\Context;
use Bref\Event\Sqs\SqsEvent;
use Bref\Event\Sqs\SqsHandler;
use Bref\LaravelBridge\Queue\Worker;
use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Context as LogContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use LogicException;

/**
 * The Lambda handler of the consumer functions (chain and webhooks): one invocation receives a batch of
 * SQS messages and answers which of them failed (partial batch response, ReportBatchItemFailures), so a
 * message that fails never drags the others back to the queue.
 *
 * Each record becomes the job of task 1 (SqsEventsQueue::jobFor, the same mapping and handlers), run by
 * Laravel's queue worker one after another:
 *  - a job that succeeds, or fails for good on its own, deletes its message: not reported;
 *  - a job that throws is released with the wait of its backoff (ChangeMessageVisibility) and reported, so
 *    it comes back after that wait; each receive counts as an attempt, and after the maxReceiveCount of
 *    the redrive policy SQS moves the message to the dead-letter queue (SqsEventJob);
 *  - an event due later is left invisible until it is due, and reported;
 *  - a record whose job would not finish before the deadline of the invocation is made visible again at
 *    once, and reported, for the next invocation.
 * A job that outlives its own timeout stops the process (the worker's alarm): the invocation fails and
 * the messages not deleted yet come back after the visibility timeout of their queue, as when a consumer
 * dies. Every handler is idempotent, so a message handled twice does no work twice.
 *
 * Each record starts from an empty Context, so nothing of the job before it reaches its payload or its log
 * lines: its job resumes the journey of its event (docs/observability.md).
 */
final class SqsEventsHandler extends SqsHandler
{
    /** Seconds kept besides the timeout of a job: the end of the job and the answer of the invocation. */
    public const int MARGIN_SECONDS = 3;

    /** Timeout of a job that declares none, as the queue workers of the local stack use. */
    private const int DEFAULT_TIMEOUT = 35;

    /** @var list<string> The ids of the messages of the batch this process is handling. */
    private static array $batch = [];

    public function __construct(private readonly Container $container) {}

    /**
     * The ids of the messages of the batch this process is handling, for the logs of a job and for make
     * cloud-check; empty outside a batch.
     *
     * @return list<string>
     */
    public static function currentBatch(): array
    {
        return self::$batch;
    }

    public function handleSqs(SqsEvent $event, Context $context): void
    {
        $queue = $this->container->make(QueueManager::class)->connection('sqs');

        if (! $queue instanceof SqsEventsQueue) {
            throw new LogicException('The queue connection sqs must use the driver sqs-events.');
        }

        $worker = $this->container->makeWith(Worker::class, [
            'isDownForMaintenance' => fn (): bool => false,
            'resetScope' => fn () => $this->resetScope(),
        ]);

        self::$batch = array_values(array_map(fn ($record): string => $record->getMessageId(), $event->getRecords()));

        try {
            foreach ($event->getRecords() as $record) {
                LogContext::flush();
                $source = $record->toArray()['eventSourceARN'] ?? null;
                $url = $queue->urlOfArn(is_string($source) ? $source : '');
                $message = LambdaSqsRecord::toMessage($record);
                $job = $queue->jobFor($message, $url);

                if ($job === null) {
                    $this->markAsFailed($record);

                    continue;
                }

                $timeout = $job->timeout() ?? self::DEFAULT_TIMEOUT;

                if ($context->getRemainingTimeInMillis() < ($timeout + self::MARGIN_SECONDS) * 1000) {
                    $queue->returnNow($message, $url);
                    $this->markAsFailed($record);

                    continue;
                }

                $worker->runSqsJob($job, 'sqs', new WorkerOptions(name: 'sqs', memory: 512, timeout: $timeout, sleep: 0, maxTries: 0));

                if (! $job->isDeleted()) {
                    $this->markAsFailed($record);
                }
            }
        } finally {
            self::$batch = [];
            LogContext::flush();

            // A function keeps its process between invocations and is frozen in between: it gives its
            // database connection back, so idle environments hold none (docs/infrastructure.md).
            DB::disconnect();
        }
    }

    /** What Laravel's queue worker resets before each job, so no job sees the state of the previous one. */
    private function resetScope(): void
    {
        $log = $this->container->make(LogManager::class);
        $log->flushSharedContext();
        $log->withoutContext();

        foreach (DB::getConnections() as $connection) {
            $connection->resetTotalQueryDuration();
            $connection->allowQueryDurationHandlersToRunAgain();
        }

        $this->container->forgetScopedInstances();
        Facade::clearResolvedInstances();
    }
}
