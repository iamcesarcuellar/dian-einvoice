<?php

namespace App\Support\Outbox\Sqs;

use App\Support\Observability\LogEvent;
use App\Support\Observability\ObservabilityHooks;
use App\Support\Observability\Redactor;
use App\Support\Observability\Telemetry;
use Aws\Sqs\SqsClient;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\SqsJob;
use Illuminate\Queue\ManuallyFailedException;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

/**
 * One received SQS message as a Laravel job, with the payload SqsEventsQueue built for it. Attempts are
 * the receives SQS counts, the most attempts are the maxReceiveCount of the redrive policy of the queue,
 * a release changes the visibility of the message for the wait of the backoff, and a success deletes it.
 *
 * Two ways to fail:
 *  - the job fails itself while it runs (deadLetter() of a step after the chain ended as failed, with the
 *    reason on the document): a copy of its message, with the reason in the attribute dead_letter_reason,
 *    goes to the dead-letter queue of its queue at once, then the message is deleted and the failure
 *    recorded as locally. Moving that copy back to the queue (the native redrive of SQS) retries the step
 *    once the cause is fixed (ChainStep, DeliveryJob; docs/runbooks/dead-letters.md);
 *  - the job keeps throwing until its last receive: its failed() method runs, so no chain is left
 *    stuck, and the message stays in the queue, visible at once, so SQS moves it to the dead-letter
 *    queue on its next receive (the redrive policy).
 */
final class SqsEventJob extends SqsJob
{
    /** The message attribute of a copy in the dead-letter queue that says why its job gave up. */
    public const string DEAD_LETTER_REASON = 'dead_letter_reason';

    /** SQS takes at most 10 message attributes. */
    private const int MAX_ATTRIBUTES = 10;

    private bool $running = false;

    /**
     * @param  array<mixed>  $job  The SQS message.
     */
    public function __construct(
        Container $container,
        SqsClient $sqs,
        array $job,
        string $connectionName,
        string $queue,
        private readonly string $payload,
        private readonly ?int $maxReceives,
        private readonly ?string $deadLetters = null,
    ) {
        parent::__construct($container, $sqs, $job, $connectionName, $queue);
    }

    public function getRawBody(): string
    {
        return $this->payload;
    }

    public function maxTries(): ?int
    {
        $tries = parent::maxTries();

        return $this->maxReceives ?? (is_numeric($tries) ? (int) $tries : null);
    }

    public function fire(): void
    {
        $this->running = true;

        try {
            parent::fire();
        } finally {
            $this->running = false;
        }
    }

    /**
     * @param  Throwable|null  $e
     */
    public function fail($e = null): void
    {
        $failedItself = $this->running && ! $e instanceof TimeoutExceededException;

        if ($failedItself) {
            $this->copyToDeadLetters($e);
        }

        if ($failedItself || $this->maxReceives === null || $this->attempts() < $this->maxReceives) {
            parent::fail($e);

            return;
        }

        $this->markAsFailed();

        try {
            $this->failed($e);
        } finally {
            $this->changeMessageVisibilityInSqs(0);
            $this->container->make(Dispatcher::class)->dispatch(new JobFailed($this->connectionName, $this, $e ?? new ManuallyFailedException));
        }
    }

    /**
     * A copy of the message in the dead-letter queue of its queue, with the body and the attributes it came with
     * and the reason its job gave up. Without a dead-letter queue, or when SQS does not take the copy, the failure
     * stays recorded as locally (the document failed, failed_jobs): the message is deleted either way, so a failure
     * never loops.
     */
    private function copyToDeadLetters(?Throwable $e): void
    {
        if ($this->deadLetters === null) {
            return;
        }

        $class = class_basename($this->resolveName());
        $queue = ObservabilityHooks::queueName($this->getQueue());
        $reason = Redactor::text($e?->getMessage() ?? 'The job failed.', 256);
        $attributes = [];

        // The attributes of the message, as SQS gave them: a type and a string or binary value each.
        foreach (is_array($this->job['MessageAttributes'] ?? null) ? $this->job['MessageAttributes'] : [] as $name => $attribute) {
            if (! is_string($name) || $name === self::DEAD_LETTER_REASON || ! is_array($attribute) || count($attributes) >= self::MAX_ATTRIBUTES - 1) {
                continue;
            }

            $type = is_string($attribute['DataType'] ?? null) ? $attribute['DataType'] : 'String';

            if (is_string($attribute['StringValue'] ?? null)) {
                $attributes[$name] = ['DataType' => $type, 'StringValue' => $attribute['StringValue']];
            } elseif (is_string($attribute['BinaryValue'] ?? null)) {
                $attributes[$name] = ['DataType' => $type, 'BinaryValue' => $attribute['BinaryValue']];
            }
        }

        try {
            $this->sqs->sendMessage([
                'QueueUrl' => $this->deadLetters,
                'MessageBody' => is_string($this->job['Body'] ?? null) ? $this->job['Body'] : '',
                'MessageAttributes' => [...$attributes, self::DEAD_LETTER_REASON => ['DataType' => 'String', 'StringValue' => $reason]],
            ]);

            Telemetry::record(LogEvent::QueueDeadLetter, sprintf('%s gave up for good: a copy of its message is in the dead-letter queue of %s.', $class, $queue), outcome: 'failure', detail: ['job' => $class, 'queue' => $queue, 'reason' => $reason], level: 'warning');
        } catch (Throwable $refused) {
            Telemetry::record(LogEvent::QueueDeadLetter, sprintf('%s gave up for good, and its message could not be copied to the dead-letter queue of %s: the failure stays recorded on its document.', $class, $queue), outcome: 'failure', detail: ['job' => $class, 'queue' => $queue, 'reason' => $reason], level: 'error', error: $refused);
        }
    }
}
