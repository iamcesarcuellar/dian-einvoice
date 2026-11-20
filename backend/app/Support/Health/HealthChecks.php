<?php

namespace App\Support\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Throwable;

/**
 * The services the API, the workers and the relay need, as config health.checks names them: locally the
 * database and Redis (queue, cache and sessions); in the emulated cloud the database, the cache store
 * (DynamoDB, as the sessions and the rate limits) and the queue (SQS). /api/v1/health and
 * php artisan health:check run them.
 */
final class HealthChecks
{
    /** What each check says when it fails. */
    public const array FAILURES = [
        'database' => 'the database is not reachable',
        'redis' => 'Redis is not reachable',
        'cache' => 'the cache store is not reachable',
        'queue' => 'the queue is not reachable',
    ];

    /**
     * Runs every check, reporting the errors; true for each one that passed.
     *
     * @return array<string, bool>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->names() as $name) {
            try {
                $this->check($name);
                $results[$name] = true;
            } catch (Throwable $exception) {
                report($exception);
                $results[$name] = false;
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return config('health.checks');
    }

    private function check(string $name): void
    {
        match ($name) {
            'database' => DB::select('select 1'),
            'redis' => Redis::connection()->ping(),
            'cache' => Cache::store()->get('health:check'),
            'queue' => Queue::connection()->size(),
            default => throw new InvalidArgumentException("Unknown health check {$name}."),
        };
    }
}
