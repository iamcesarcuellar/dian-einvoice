<?php

namespace App\Support\Outbox;

use Illuminate\Contracts\Container\Container;

/**
 * Every periodic task tagged as a Sweeper (stalled chains, deliveries and webhooks, expired idempotency
 * keys, the retention pruning), run once. Locally the relay process runs them every half minute; in the
 * functions, the Laravel scheduler of the scheduler function runs them every minute (outbox:sweep).
 */
final class Sweepers
{
    public function __construct(private readonly Container $container) {}

    /**
     * Runs each sweeper once; returns what each one fixed, by its short class name.
     *
     * @return array<string, int>
     */
    public function run(): array
    {
        $fixed = [];

        foreach ($this->container->tagged(Sweeper::class) as $sweeper) {
            /** @var Sweeper $sweeper */
            $fixed[class_basename($sweeper)] = $sweeper->sweep();
        }

        return $fixed;
    }
}
