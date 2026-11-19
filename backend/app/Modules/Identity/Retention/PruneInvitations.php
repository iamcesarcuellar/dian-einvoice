<?php

namespace App\Modules\Identity\Retention;

use App\Support\Retention\Pruner;
use App\Support\Retention\Retention;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Invitations never accepted that expired or were canceled before the cutoff; accepted ones stay. */
final class PruneInvitations implements Pruner
{
    public function name(): string
    {
        return 'invitations';
    }

    public function prune(CarbonInterface $before): int
    {
        return Retention::inBatches(fn (): int => DB::table('team_invitations')
            ->whereNull('accepted_at')
            ->where(fn (Builder $query) => $query->where('expires_at', '<', $before)->orWhere('canceled_at', '<', $before))
            ->orderBy('id')
            ->limit(Retention::BATCH)
            ->delete());
    }
}
