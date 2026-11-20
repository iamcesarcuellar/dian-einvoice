<?php

namespace App\Support\Outbox;

use App\Support\Health\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The relay process of the local stack (compose service relay): publishes batches of outbox events while
 * there are any, sleeps briefly when there are none, and every half minute runs the sweepers, such as the
 * one that restarts stalled chains. Errors are reported and retried; SIGTERM stops it between batches.
 * The functions have no such process: see outbox:publish-pending and outbox:sweep.
 */
final class RelayCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Publish one batch, run the sweepers and exit}
        {--sleep=200 : Milliseconds to wait when there is nothing to publish}';

    protected $description = 'Publish committed outbox events to the queue, in order and at least once';

    private const int SWEEP_SECONDS = 30;

    private bool $stopping = false;

    public function handle(OutboxRelay $relay, OutboxPause $pause, Sweepers $sweepers): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopping = true);
            pcntl_signal(SIGINT, fn () => $this->stopping = true);
        }

        $sleep = max(10, (int) $this->option('sleep')) * 1000;
        $lastSweep = 0.0;

        $this->info('Outbox relay started.');

        while (! $this->stopping) {
            $published = 0;
            Heartbeat::beat('relay');

            try {
                if (! $pause->active()) {
                    $published = $relay->publishBatch();

                    if ($published > 0) {
                        $this->line(sprintf('[%s] Published %d events.', Carbon::now()->toDateTimeString(), $published));
                    }

                    if (microtime(true) - $lastSweep >= self::SWEEP_SECONDS || $this->option('once')) {
                        $lastSweep = microtime(true);
                        $this->sweep($sweepers);
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                DB::disconnect();
                usleep(1_000_000);
            }

            if ($this->option('once')) {
                break;
            }

            if ($published < OutboxRelay::BATCH) {
                usleep($sleep);
            }
        }

        $this->info('Outbox relay stopped.');

        return self::SUCCESS;
    }

    private function sweep(Sweepers $sweepers): void
    {
        foreach (array_filter($sweepers->run()) as $sweeper => $fixed) {
            $this->warn(sprintf('[%s] %s restarted %d stalled items.', Carbon::now()->toDateTimeString(), $sweeper, $fixed));
        }
    }
}
