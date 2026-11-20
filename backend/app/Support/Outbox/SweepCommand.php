<?php

namespace App\Support\Outbox;

use Illuminate\Console\Command;

/**
 * Runs every sweeper once, by hand: what the local relay process does every half minute, and what the scheduler
 * function runs once a minute as a task of its own process (ScheduledSweep, bootstrap/app.php).
 */
final class SweepCommand extends Command
{
    protected $signature = 'outbox:sweep';

    protected $description = 'Run the sweepers once: restart stalled chains, deliveries and webhooks, and prune';

    public function handle(ScheduledSweep $sweep): int
    {
        $fixed = $sweep();

        if ($fixed === null) {
            $this->line('The outbox is paused (the demo seeder runs its chains in line): no sweep.');

            return self::SUCCESS;
        }

        foreach (array_filter($fixed) as $sweeper => $count) {
            $this->warn(sprintf('%s restarted %d stalled items.', $sweeper, $count));
        }

        return self::SUCCESS;
    }
}
