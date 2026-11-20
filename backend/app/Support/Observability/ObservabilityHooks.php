<?php

namespace App\Support\Observability;

use Illuminate\Auth\Events\Failed;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Where the journeys that do not start with a request begin, and the lines of the work that runs outside one:
 *  - a command begins a journey unless it was given one (the console handler of the functions passes the one of
 *    its invocation in Laravel's __LARAVEL_CONTEXT), so a scheduled run is one journey;
 *  - a queued job runs inside the journey its payload carries (Laravel's Context, restored as it starts) and, in
 *    Lambda, as the invocation of the function that runs it (request_id); in the functions (config
 *    observability.jobs) it writes one line when it ends: its class, queue, attempt, time and outcome;
 *  - a task of the Laravel scheduler writes one line when it runs;
 *  - an email carries its journey in X-Correlation-Id;
 *  - a sign-in with a wrong password writes one line (auth.refused), never with the address it named.
 */
final class ObservabilityHooks
{
    /** @var array<string, int|float> Moment each running job started, by its id. */
    private static array $started = [];

    public static function register(Dispatcher $events): void
    {
        $events->listen(CommandStarting::class, fn () => Correlation::ensure());

        // After Laravel's own listener, which restores the Context of the payload.
        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $invocation = $_SERVER['LAMBDA_REQUEST_ID'] ?? null;

            if (is_string($invocation) && $invocation !== '') {
                Context::add('request_id', $invocation);
            }

            // A job acts as the system, on the company and in the journey its payload carries.
            Context::add('actor', 'system');
            Correlation::ensure();
            self::$started[self::key($event->job)] = hrtime(true);
        });

        $events->listen(JobProcessed::class, fn (JobProcessed $event) => self::ended($event->job, $event->job->hasFailed() ? 'failure' : ($event->job->isReleased() ? 'retried' : 'success'), null));
        $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $event) => self::ended($event->job, 'retried', null));
        $events->listen(JobFailed::class, fn (JobFailed $event) => self::ended($event->job, 'failure', $event->exception));

        $events->listen(Failed::class, fn () => Telemetry::record(LogEvent::AuthRefused, 'A sign-in was refused: wrong email or password.', outcome: 'rejected', detail: ['credential' => 'password'], level: 'warning'));

        $events->listen(MessageSending::class, function (MessageSending $event): void {
            $id = Correlation::current();
            $headers = $event->message->getHeaders();

            if ($id !== null && ! $headers->has(LogSchema::correlationHeader())) {
                $headers->addTextHeader(LogSchema::correlationHeader(), $id);
            }
        });

        $events->listen(ScheduledTaskFinished::class, fn (ScheduledTaskFinished $event) => self::task($event->task->description ?? $event->task->getSummaryForDisplay(), 'success', $event->runtime * 1000, null));
        $events->listen(ScheduledTaskFailed::class, fn (ScheduledTaskFailed $event) => self::task($event->task->description ?? $event->task->getSummaryForDisplay(), 'failure', null, $event->exception));
        $events->listen(ScheduledTaskSkipped::class, fn (ScheduledTaskSkipped $event) => self::task($event->task->description ?? $event->task->getSummaryForDisplay(), 'skipped', null, null));
    }

    /** The first event that ends a job writes its line: a job that fails itself ends again as processed. */
    private static function ended(Job $job, string $outcome, ?Throwable $error): void
    {
        $key = self::key($job);
        $started = self::$started[$key] ?? null;
        unset(self::$started[$key]);

        if ($started === null || ! config()->boolean('observability.jobs')) {
            return;
        }

        $class = class_basename($job->resolveName());

        Telemetry::record(
            LogEvent::QueueJob,
            sprintf('%s %s after attempt %d.', $class, match ($outcome) {
                'success' => 'succeeded',
                'retried' => 'was released for a retry',
                default => 'failed',
            }, $job->attempts()),
            outcome: $outcome,
            durationMs: Telemetry::since($started),
            detail: ['job' => $class, 'queue' => self::queue($job->getQueue()), 'attempt' => $job->attempts()],
            level: $outcome === 'failure' ? 'error' : ($outcome === 'retried' ? 'warning' : 'info'),
            error: $error,
        );
    }

    private static function task(string $task, string $outcome, ?float $durationMs, ?Throwable $error): void
    {
        if (! config()->boolean('observability.jobs')) {
            return;
        }

        Telemetry::record(
            LogEvent::SchedulerTask,
            sprintf('The scheduled task %s %s.', $task, match ($outcome) {
                'success' => 'ran',
                'skipped' => 'was skipped',
                default => 'failed',
            }),
            outcome: $outcome,
            durationMs: $durationMs,
            detail: ['task' => $task],
            level: $outcome === 'failure' ? 'error' : 'info',
            error: $error,
        );
    }

    private static function key(Job $job): string
    {
        return $job->getConnectionName().'|'.$job->getJobId();
    }

    /** The channel of an SQS queue (its URL ends in dian-einvoice-<stage>-<channel>), or the name of a local queue. */
    private static function queue(string $queue): string
    {
        $name = basename((string) (parse_url($queue, PHP_URL_PATH) ?: $queue));

        return (string) preg_replace('/^dian-einvoice-[a-z0-9]+-/', '', $name);
    }
}
