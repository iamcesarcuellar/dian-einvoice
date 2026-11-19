<?php

namespace App\Support\Health;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The healthcheck of the containers without an HTTP port: the database and Redis answer, and, for
 * a queue worker or the outbox relay, its loop beat in the last MAX_AGE seconds (a job may hold a
 * worker for its 35 s timeout; the relay sleeps less than a second between batches). Exit code 0
 * when healthy, 1 otherwise, one line saying why.
 */
final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check {process? : worker or relay: also check the heartbeat of its loop}';

    protected $description = 'Check the database, Redis and the heartbeat of a worker or the relay (healthcheck of their containers)';

    /** Seconds without a beat after which the process counts as stuck. */
    public const int MAX_AGE = 90;

    public function handle(): int
    {
        $process = $this->argument('process');
        $checks = [];

        try {
            DB::select('select 1');
            $checks[] = 'database ok';
        } catch (Throwable) {
            return $this->unhealthy('the database is not reachable');
        }

        try {
            Redis::connection()->ping();
            $checks[] = 'redis ok';
        } catch (Throwable) {
            return $this->unhealthy('Redis is not reachable');
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
