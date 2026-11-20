<?php

namespace App\Support\Cloud;

use App\Support\Outbox\Sqs\SqsEventsHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A development tool of make cloud-check: a job that does nothing but note, in the shared cache, each time
 * it runs and the batch of SQS messages it ran in. The check sends a few of them with a message no handler
 * can read, in one batch, to see the partial batch response of the chain function at work.
 */
final class CloudCheckProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly string $marker, public readonly int $index) {}

    public function handle(): void
    {
        // Cache stores give numbers back as text.
        $runs = Cache::get(self::runsKey($this->marker, $this->index));
        Cache::put(self::runsKey($this->marker, $this->index), (is_numeric($runs) ? (int) $runs : 0) + 1, 3600);
        Cache::put(self::batchKey($this->marker, $this->index), SqsEventsHandler::currentBatch(), 3600);
    }

    public static function runsKey(string $marker, int $index): string
    {
        return "cloud-check:probe:{$marker}:{$index}:runs";
    }

    public static function batchKey(string $marker, int $index): string
    {
        return "cloud-check:probe:{$marker}:{$index}:batch";
    }
}
