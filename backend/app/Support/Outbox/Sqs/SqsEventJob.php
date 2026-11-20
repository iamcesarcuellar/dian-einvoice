<?php

namespace App\Support\Outbox\Sqs;

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
 *    reason on the document): the message was handled, so it is deleted and the failure recorded as
 *    locally;
 *  - the job keeps throwing until its last receive: its failed() method runs, so no chain is left
 *    stuck, and the message stays in the queue, visible at once, so SQS moves it to the dead-letter
 *    queue on its next receive (the redrive policy).
 */
final class SqsEventJob extends SqsJob
{
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
}
