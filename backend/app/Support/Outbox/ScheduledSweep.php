<?php

namespace App\Support\Outbox;

use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;

/**
 * Every sweeper once, as a task of the Laravel scheduler of the scheduler function (bootstrap/app.php), in the
 * process of the scheduler: what each restarted goes to the log (outbox.swept), so nothing prints outside it.
 * Held back while the demo seeder pauses the outbox. outbox:sweep runs it by hand.
 */
final class ScheduledSweep
{
    public function __construct(
        private readonly Sweepers $sweepers,
        private readonly OutboxPause $pause,
    ) {}

    /**
     * What each sweeper restarted, by its short class name; null while the outbox is paused.
     *
     * @return array<string, int>|null
     */
    public function __invoke(): ?array
    {
        if ($this->pause->active()) {
            return null;
        }

        $fixed = $this->sweepers->run();

        foreach (array_filter($fixed) as $sweeper => $count) {
            Telemetry::record(
                LogEvent::OutboxSwept,
                sprintf('%s restarted %d stalled items.', $sweeper, $count),
                outcome: 'success',
                detail: ['sweeper' => $sweeper, 'count' => $count],
                level: 'warning',
            );
        }

        return $fixed;
    }
}
