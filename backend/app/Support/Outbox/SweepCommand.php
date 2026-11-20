<?php

namespace App\Support\Outbox;

use Illuminate\Console\Command;

/**
 * Runs every sweeper once: what the local relay process does every half minute, as a scheduled task of
 * the scheduler function (bootstrap/app.php), which runs it once a minute on one process at a time.
 */
final class SweepCommand extends Command
{
    protected $signature = 'outbox:sweep';

    protected $description = 'Run the sweepers once: restart stalled chains, deliveries and webhooks, and prune';

    public function handle(Sweepers $sweepers, OutboxPause $pause): int
    {
        if ($pause->active()) {
            $this->line('The outbox is paused (the demo seeder runs its chains in line): no sweep.');

            return self::SUCCESS;
        }

        foreach ($sweepers->run() as $sweeper => $fixed) {
            if ($fixed > 0) {
                $this->warn(sprintf('%s restarted %d stalled items.', $sweeper, $fixed));
            }
        }

        return self::SUCCESS;
    }
}
