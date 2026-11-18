<?php

namespace App\Modules\Dian\Pipeline;

use App\Support\Outbox\OutboxEvent;
use App\Support\Outbox\OutboxSubscribers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Runs the chain of one document in line, in this process, instead of through the relay and the
 * workers: it takes the unpublished outbox events of the document in order, marks them published
 * and runs their jobs right away, until none is left. A retry scheduled for later moves the clock
 * there (Carbon test time) instead of waiting. The steps, the simulator calls and the outbox are
 * the real ones. Only the demo seeder uses it, while the workers and the relay are stopped.
 */
final class InlineChain
{
    /** More than any chain needs: 4 steps, 6 attempts, GetStatus checks. */
    private const int MAX_EVENTS = 60;

    public function __construct(private readonly OutboxSubscribers $subscribers) {}

    public function run(string $documentId): void
    {
        for ($handled = 0; $handled < self::MAX_EVENTS; $handled++) {
            $event = DB::transaction(function () use ($documentId): ?OutboxEvent {
                $event = OutboxEvent::query()
                    ->where('aggregate_id', $documentId)
                    ->whereNull('published_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $event?->forceFill(['published_at' => Carbon::now()])->save();

                return $event;
            });

            if ($event === null) {
                return;
            }

            if ($event->available_at !== null && $event->available_at->isFuture()) {
                Carbon::setTestNow($event->available_at);
            }

            foreach ($this->subscribers->jobsFor($event) as $job) {
                app()->call([$job, 'handle']);
            }
        }

        throw new LogicException("The chain of document {$documentId} did not end after ".self::MAX_EVENTS.' events.');
    }
}
