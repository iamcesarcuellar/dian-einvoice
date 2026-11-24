<?php

namespace App\Support\Retention;

use Illuminate\Console\Command;

/** Prunes now what the outbox sweepers prune every hour (ScheduledPruning), in the relay or the scheduler. */
final class PruneCommand extends Command
{
    protected $signature = 'retention:prune';

    protected $description = 'Delete operational records older than their retention (config/retention.php)';

    public function handle(Retention $retention): int
    {
        foreach ($retention->prune() as $name => $count) {
            $this->line(sprintf('%-20s %d deleted', $name, $count));
        }

        return self::SUCCESS;
    }
}
