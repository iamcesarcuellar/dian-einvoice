<?php

namespace App\Support\Outbox;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * An operation command of the runbooks (docs/runbooks/outbox-relay.md): the state of the outbox across companies,
 * read only. How many events wait to be published and how old the oldest is (the lag the alarm outbox-lag watches),
 * of which types, how many were published in the last minutes, when the relay function last ran (relay-not-running),
 * and whether the demo seeder paused it. It prints counts, types and times, never a payload.
 *
 *   make cloud-artisan ARGS="outbox:status"
 */
final class OutboxStatusCommand extends Command
{
    protected $signature = 'outbox:status {--minutes=15 : The window of the events published}';

    protected $description = 'The state of the outbox across companies: events waiting, the oldest, the last run of the relay (an operation command of the runbooks)';

    /** The age beyond which an unpublished event is late: the threshold of the alarm outbox-lag. */
    public const int LATE_SECONDS = 120;

    /** The relay runs once a minute: three minutes without a run is the alarm relay-not-running. */
    public const int RELAY_SILENT_SECONDS = 180;

    public function handle(OutboxPause $pause): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $now = Carbon::now();
        $pending = OutboxEvent::query()->whereNull('published_at');
        $count = (clone $pending)->count();
        $oldest = (clone $pending)->min('created_at');
        $lag = is_string($oldest) ? (int) max(0, Carbon::parse($oldest)->diffInSeconds($now)) : 0;
        $published = OutboxEvent::query()->where('published_at', '>=', $now->copy()->subMinutes($minutes))->count();
        $lastRun = RelayHeartbeat::lastRun();
        $sinceRun = $lastRun === null ? null : $now->getTimestamp() - $lastRun;

        $this->line(sprintf('The outbox, across companies, at %s:', $now->toIso8601ZuluString()));
        $this->line(sprintf('  unpublished: %d events%s.', $count, $count === 0 ? '' : sprintf(', the oldest %d s old (the alarm outbox-lag looks for more than %d s)', $lag, self::LATE_SECONDS)));

        $types = (clone $pending)->selectRaw('type, count(*) as events')->groupBy('type')->orderByDesc('events')->limit(10)->pluck('events', 'type');

        foreach ($types as $type => $events) {
            $this->line(sprintf('    %-40s %d', $type, is_numeric($events) ? (int) $events : 0));
        }

        $this->line(sprintf('  published in the last %d minutes: %d events.', $minutes, $published));
        $this->line(sprintf('  the relay (the safety net, once a minute): %s.', $sinceRun === null ? 'never ran here' : "last ran {$sinceRun} s ago"));
        $this->line(sprintf('  paused by the demo seeder: %s.', $pause->active() ? 'yes, until it ends' : 'no'));

        $this->line(match (true) {
            $sinceRun === null || $sinceRun > self::RELAY_SILENT_SECONDS => '  verdict: the relay does not run; events its writers left behind wait (docs/runbooks/outbox-relay.md).',
            $lag > self::LATE_SECONDS => '  verdict: events wait longer than the relay should allow: the topic refuses them, or the relay cannot keep up (docs/runbooks/outbox-relay.md).',
            default => '  verdict: the outbox moves.',
        });

        return self::SUCCESS;
    }
}
