<?php

namespace App\Support\Health;

use Illuminate\Console\Command;

/**
 * The healthcheck of the containers without an HTTP port: the services they need answer (HealthChecks:
 * the database and Redis locally; the database, the cache store and the queue in the emulated cloud),
 * and, for a queue worker or the outbox relay, its loop beat in the last MAX_AGE seconds (a job may hold
 * a worker for its 35 s timeout; the relay sleeps less than a second between batches). Exit code 0
 * when healthy, 1 otherwise, one line saying why.
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {process? : worker or relay: also check the heartbeat of its loop}';

    protected $description = 'Check the database, Redis or the cloud services, and the heartbeat of a worker or the relay (healthcheck of their containers)';

    /** Seconds without a beat after which the process counts as stuck. */
    public const int MAX_AGE = 90;

    public function handle(HealthChecks $health): int
    {
        $process = $this->argument('process');
        $checks = [];

        foreach ($health->run() as $name => $passed) {
            if (! $passed) {
                return $this->unhealthy(HealthChecks::FAILURES[$name] ?? "the check {$name} failed");
            }

            $checks[] = "{$name} ok";
        }

        if (is_string($process)) {
            if (! in_array($process, ['worker', 'relay'], true)) {
                return $this->unhealthy("unknown process {$process}: worker or relay");
            }

            $age = Heartbeat::age($process);

            if ($age === null || $age > self::MAX_AGE) {
                return $this->unhealthy($age === null ? "the {$process} has not started its loop" : "the {$process} has not looped for {$age} s");
            }

            $checks[] = "{$process} looped {$age} s ago";
        }

        $this->line('healthy: '.implode(', ', $checks));

        return self::SUCCESS;
    }

    private function unhealthy(string $reason): int
    {
        $this->line("unhealthy: {$reason}");

        return self::FAILURE;
    }
}
