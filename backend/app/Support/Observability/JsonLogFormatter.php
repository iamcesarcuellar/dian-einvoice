<?php

namespace App\Support\Observability;

use DateTimeZone;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Throwable;

/**
 * The structured log line of the functions: one JSON object per line with exactly the fields of
 * resources/observability/log-schema.json, in their order, each one present (null when it does not apply), error
 * and detail only when there is one. The stderr channel of the functions uses it (LOG_STDERR_FORMATTER), so
 * CloudWatch Logs reads every field, the metric filters of the CDK app count them and the saved queries filter on
 * them. The correlation, request, company, actor and document come from the context of the call or from Laravel's
 * Context; anything else in them is dropped, detail keeps the keys the schema lists, and every text goes through
 * the Redactor. A record of the framework or of a library gets the same fields, with event null.
 */
final class JsonLogFormatter implements FormatterInterface
{
    private const string INTERNAL_ID = '/^[0-9A-Za-z]{26}$/';

    private const string REQUEST_ID = '/^[A-Za-z0-9._:-]{8,128}$/';

    public function format(LogRecord $record): string
    {
        return self::encode(self::line($record))."\n";
    }

    /**
     * @param  array<LogRecord>  $records
     */
    public function formatBatch(array $records): string
    {
        return implode('', array_map(fn (LogRecord $record): string => $this->format($record), $records));
    }

    /**
     * The fields of the line of one record.
     *
     * @return array<string, mixed>
     */
    public static function line(LogRecord $record): array
    {
        $context = $record->context;
        $extra = $record->extra;
        $value = fn (string $key): mixed => $context[$key] ?? $extra[$key] ?? null;
        $levels = LogSchema::values('level');
        $level = $record->level->toPsrLogLevel();

        $line = [
            'time' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => in_array($level, $levels, true) || $levels === [] ? $level : 'info',
            'message' => Redactor::text($record->message, LogSchema::limit('message', 500)),
            'function' => self::function(),
            'stage' => self::stage(),
            'correlation_id' => Correlation::accept(self::string($value(Correlation::CONTEXT))),
            'request_id' => self::matching($value('request_id'), self::REQUEST_ID) ?? self::matching($_SERVER['LAMBDA_REQUEST_ID'] ?? null, self::REQUEST_ID),
            'company_id' => self::matching($value('company_id'), self::INTERNAL_ID),
            'actor' => self::actor($value('actor')),
            'document_id' => self::matching($value('document_id'), self::INTERNAL_ID),
            'event' => self::event($context['event'] ?? null),
            'duration_ms' => is_int($context['duration_ms'] ?? null) || is_float($context['duration_ms'] ?? null) ? round((float) $context['duration_ms'], 1) : null,
            'outcome' => self::outcome($context['outcome'] ?? null),
        ];

        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $line['error'] = self::error($exception);
        }

        $detail = self::detail(is_array($context['detail'] ?? null) ? $context['detail'] : []);

        if ($detail !== []) {
            $line['detail'] = $detail;
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    public static function encode(array $line): string
    {
        $json = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return is_string($json) ? $json : '{"level":"error","message":"A log line could not be encoded."}';
    }

    /** The function that writes: its name without dian-einvoice-<stage>- in Lambda, the process elsewhere. */
    public static function function(): string
    {
        $configured = config('observability.function');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $lambda = $_SERVER['AWS_LAMBDA_FUNCTION_NAME'] ?? getenv('AWS_LAMBDA_FUNCTION_NAME');

        if (is_string($lambda) && $lambda !== '') {
            return (string) preg_replace('/^dian-einvoice-[a-z0-9]+-/', '', $lambda);
        }

        return PHP_SAPI === 'cli' ? 'console' : 'api';
    }

    public static function stage(): string
    {
        $stage = config('observability.stage');

        return is_string($stage) && $stage !== '' ? $stage : 'local';
    }

    /**
     * The class, the masked message and the first frames of an error, as file:line function, without arguments.
     *
     * @return array{class: string, message: string, trace: list<string>}
     */
    public static function error(Throwable $exception): array
    {
        $base = rtrim(base_path(), '/').'/';
        $relative = fn (string $file): string => str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
        $frames = [$relative($exception->getFile()).':'.$exception->getLine()];

        foreach ($exception->getTrace() as $frame) {
            $location = isset($frame['file']) ? $relative($frame['file']).':'.($frame['line'] ?? 0) : '[internal]';
            $function = (isset($frame['class']) ? $frame['class'].($frame['type'] ?? '::') : '').$frame['function'];
            $frames[] = "{$location} {$function}";
        }

        return [
            'class' => $exception::class,
            'message' => Redactor::text($exception->getMessage(), LogSchema::limit('errorMessage', 500)),
            'trace' => array_slice($frames, 0, LogSchema::limit('traceFrames', 8)),
        ];
    }

    /**
     * The keys of detail the schema lists, each of its type; strings masked and cut. Anything else is dropped.
     *
     * @param  array<mixed>  $given
     * @return array<string, string|int|float|bool>
     */
    private static function detail(array $given): array
    {
        $detail = [];
        $limit = LogSchema::limit('detail', 200);

        foreach (LogSchema::detail() as $key => $type) {
            $value = $given[$key] ?? null;

            $typed = match (true) {
                $value === null => null,
                $type === 'integer' => is_numeric($value) ? (int) $value : null,
                $type === 'number' => is_numeric($value) ? round((float) $value, 3) : null,
                $type === 'boolean' => is_bool($value) ? $value : null,
                default => is_scalar($value) ? Redactor::text((string) $value, $limit) : null,
            };

            if ($typed !== null && $typed !== '') {
                $detail[$key] = $typed;
            }
        }

        return $detail;
    }

    private static function event(mixed $event): ?string
    {
        if ($event instanceof LogEvent) {
            return $event->value;
        }

        return is_string($event) && array_key_exists($event, LogSchema::events()) ? $event : null;
    }

    private static function outcome(mixed $outcome): ?string
    {
        return is_string($outcome) && in_array($outcome, LogSchema::values('outcome'), true) ? $outcome : null;
    }

    private static function actor(mixed $actor): ?string
    {
        $pattern = LogSchema::pattern('actor');

        return is_string($actor) && $pattern !== null && preg_match('/'.$pattern.'/', $actor) === 1 ? $actor : null;
    }

    private static function matching(mixed $value, string $pattern): ?string
    {
        return is_string($value) && preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
