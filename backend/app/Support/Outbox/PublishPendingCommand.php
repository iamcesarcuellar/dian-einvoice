<?php

namespace App\Support\Outbox;

use Illuminate\Console\Command;

/**
 * The safety net of the outbox in the functions (the relay function runs it once a minute): publishes
 * the events the fast path of their writers left behind, a process that died after its commit or a
 * publish that failed. It takes only events older than outbox.pending_after_seconds, so it leaves the
 * fresh ones to the writer about to publish them, claims them as every relay does (FOR UPDATE SKIP
 * LOCKED), and stops after outbox.pending_budget_seconds, leaving the rest to the next run. Two runs at
 * once, or a run repeated by the scheduler, never publish the same row twice at the same time.
 */
final class PublishPendingCommand extends Command
{
    protected $signature = 'outbox:publish-pending';

    protected $description = 'Publish the outbox events the fast path left behind (the relay function, once a minute)';

    public function handle(OutboxRelay $relay, OutboxPause $pause): int
    {
        if ($pause->active()) {
            $this->line('The outbox is paused (the demo seeder runs its chains in line): nothing published.');

            return self::SUCCESS;
        }

        $olderThan = max(0, config()->integer('outbox.pending_after_seconds'));
        $budget = max(1, config()->integer('outbox.pending_budget_seconds'));
        $started = microtime(true);
        $published = 0;

        do {
            $batch = $relay->publishBatch(OutboxRelay::BATCH, $olderThan);
            $published += $batch;
        } while ($batch === OutboxRelay::BATCH && microtime(true) - $started < $budget);

        $this->line(sprintf('Published %d pending outbox events older than %d s in %.1f s.', $published, $olderThan, microtime(true) - $started));

        return self::SUCCESS;
    }
}
