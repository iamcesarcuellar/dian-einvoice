<?php

namespace App\Support\Observability;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * The correlation id: one per journey, born at its first entry into the system (a request to the API, a scheduled
 * run, a command) and carried by everything that journey does: the outbox events it records (outbox_events,
 * the SNS and SQS messages), the jobs they start, the calls to the DIAN, the webhook deliveries and the emails.
 * It lives in Laravel's Context, which writes it into every log line and into the payload of every job the
 * process dispatches, and which a job brings back when it runs. A ULID in lower case
 * (resources/observability/log-schema.json); a value from outside is kept only when it has that form.
 */
final class Correlation
{
    public const string CONTEXT = 'correlation_id';

    /** The correlation id of the current journey, or null before one began. */
    public static function current(): ?string
    {
        $id = Context::get(self::CONTEXT);

        return is_string($id) && self::valid($id) ? $id : null;
    }

    /**
     * Begins a journey, keeping the given id when it has the expected form (a client that sends one, the event of
     * the outbox a job comes from), with a new one otherwise; returns it.
     */
    public static function begin(?string $candidate = null): string
    {
        $id = self::accept($candidate) ?? self::generate();
        Context::add(self::CONTEXT, $id);

        return $id;
    }

    /** The current id, beginning a journey when there is none. */
    public static function ensure(): string
    {
        return self::current() ?? self::begin();
    }

    /**
     * Runs the callback inside the journey of the given id (the event of the outbox whose jobs it dispatches),
     * then restores the journey before it.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function within(?string $id, Closure $callback): mixed
    {
        $accepted = self::accept($id);

        return $accepted === null ? $callback() : Context::scope($callback, [self::CONTEXT => $accepted]);
    }

    /** The id when it has the form of a correlation id (in any case), in lower case; null otherwise. */
    public static function accept(?string $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        $candidate = strtolower(trim($candidate));

        return self::valid($candidate) ? $candidate : null;
    }

    public static function valid(string $id): bool
    {
        return preg_match('/'.LogSchema::correlationPattern().'/', $id) === 1;
    }

    public static function generate(): string
    {
        return Str::lower((string) Str::ulid());
    }

    /**
     * The header that carries the journey to the DIAN, to a webhook endpoint or in an email, when there is one.
     *
     * @return array<string, string>
     */
    public static function headers(): array
    {
        $id = self::current();

        return $id === null ? [] : [LogSchema::correlationHeader() => $id];
    }
}
