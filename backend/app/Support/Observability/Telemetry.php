<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one event of the schema (LogEvent) to the log, with the fields JsonLogFormatter reads from the context of
 * the call: the outcome, the duration, the document and the detail the schema allows. The correlation, the
 * request, the company and the actor come from Laravel's Context. A failure to log never fails the work.
 */
final class Telemetry
{
    /**
     * @param  array<string, string|int|float|bool|null>  $detail  Keys of detail of the schema; others are dropped.
     */
    public static function record(
        LogEvent $event,
        string $message,
        ?string $outcome = null,
        ?float $durationMs = null,
        ?string $documentId = null,
        array $detail = [],
        string $level = 'info',
        ?Throwable $error = null,
    ): void {
        $context = array_filter([
            'event' => $event->value,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
            'document_id' => $documentId,
            'detail' => array_filter($detail, fn (mixed $value): bool => $value !== null),
            'exception' => $error,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
            // The log is for the operators; the work it describes goes on without it.
        }
    }

    /** Milliseconds since a moment taken with hrtime(true). */
    public static function since(int|float $started): float
    {
        return round((hrtime(true) - $started) / 1_000_000, 1);
    }
}
