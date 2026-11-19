<?php

namespace App\Support\Retention;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Jobs the queue gave up on before the cutoff (failed_jobs). */
final class PruneFailedJobs implements Pruner
{
    public function name(): string
    {
        return 'failed_jobs';
    }

    public function prune(CarbonInterface $before): int
    {
        return Retention::inBatches(fn (): int => DB::table('failed_jobs')
            ->where('failed_at', '<', $before)
            ->orderBy('id')
            ->limit(Retention::BATCH)
            ->delete());
    }
}
