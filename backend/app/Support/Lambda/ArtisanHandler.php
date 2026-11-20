<?php

namespace App\Support\Lambda;

use App\Support\Observability\Correlation;
use App\Support\Observability\JsonLogFormatter;
use App\Support\Observability\LogEvent;
use App\Support\Observability\LogSchema;
use App\Support\Observability\Redactor;
use App\Support\Observability\Telemetry;
use Bref\Context\Context;
use Bref\Event\Handler;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context as LogContext;
use Symfony\Component\Process\Process;

/**
 * The Lambda handler of the functions that run php artisan (relay, scheduler and console), in place of the console
 * runtime of Bref, which echoes everything the command prints into the log. The event is the command line, as the
 * schedules and make cloud-check send it; each invocation begins a journey (a scheduled run or an operation) and runs
 * the command in a process of its own, within the time the invocation has left, as Bref does:
 *  - what the command prints (stdout) is the output the invocation answers, never a log line;
 *  - what it writes to stderr is its log, one JSON line each (JsonLogFormatter), passed on as it is; a line that is
 *    not JSON (PHP itself, a rendered exception) is passed on as the message of a runtime.output line, and kept in
 *    the output too, as Bref keeps it, so the invoker sees why a command failed;
 *  - the invocation writes one line of its own (console.command): the name of the command, never its arguments,
 *    its exit code and its time; a command that fails fails the invocation, with its output, so Lambda counts it.
 */
final class ArtisanHandler implements Handler
{
    /** @var list<string> Output a command printed: what the invocation answers. */
    private array $output = [];

    private string $pending = '';

    /**
     * @return array{exitCode: int, output: string}
     */
    public function handle(mixed $event, Context $context): array
    {
        $cli = is_string($event) ? $event : (is_array($event) && is_string($event['cli'] ?? null) ? $event['cli'] : '');
        $command = strtok(trim($cli), " \t") ?: 'list';
        $this->output = [];
        $this->pending = '';

        // A journey per invocation, by the system, which the command resumes from Laravel's Context of its process.
        LogContext::flush();
        LogContext::add('actor', 'system');
        Correlation::begin();
        $started = hrtime(true);

        $process = Process::fromShellCommandline(
            sprintf('exec php %s %s', escapeshellarg(base_path('artisan')), $cli),
            base_path(),
            ['__LARAVEL_CONTEXT' => (string) json_encode(app(Repository::class)->dehydrate())],
            null,
            max(1, $context->getRemainingTimeInMillis() / 1000 - 1),
        );

        $process->run(function (string $type, string $buffer): void {
            if ($type === Process::OUT) {
                $this->output[] = $buffer;

                return;
            }

            $this->stderr($buffer);
        });

        $this->stderr("\n");
        $exitCode = $process->getExitCode() ?? 1;
        $output = mb_scrub(substr(implode('', $this->output), -5 * 1024 * 1024), 'UTF-8');

        Telemetry::record(
            LogEvent::ConsoleCommand,
            sprintf('php artisan %s exited with %d.', $command, $exitCode),
            outcome: $exitCode === 0 ? 'success' : 'failure',
            durationMs: Telemetry::since($started),
            detail: ['command' => $command, 'exit_code' => $exitCode],
            level: $exitCode === 0 ? 'info' : 'error',
        );

        if ($exitCode !== 0) {
            // Lambda counts the failure (its Errors metric); the invoker gets the output.
            throw new CommandFailed($output);
        }

        return ['exitCode' => $exitCode, 'output' => $output];
    }

    /** Passes the complete lines of stderr on: JSON lines as they are, any other line wrapped in one. */
    private function stderr(string $buffer): void
    {
        $this->pending .= $buffer;

        while (($end = strpos($this->pending, "\n")) !== false) {
            $line = rtrim(substr($this->pending, 0, $end), "\r");
            $this->pending = substr($this->pending, $end + 1);

            if (trim($line) === '') {
                continue;
            }

            if (self::isJson($line)) {
                fwrite(STDERR, $line."\n");

                continue;
            }

            $this->output[] = $line."\n";
            fwrite(STDERR, self::wrapped($line)."\n");
        }
    }

    private static function isJson(string $line): bool
    {
        return str_starts_with(ltrim($line), '{') && is_array(json_decode($line, true));
    }

    /** A line a process printed outside the logger, as the message of a line of the schema. */
    public static function wrapped(string $line): string
    {
        $fields = array_fill_keys(array_keys(array_filter(LogSchema::fields(), fn (array $field): bool => ! $field['optional'])), null);

        return JsonLogFormatter::encode([
            ...$fields,
            'time' => Carbon::now('UTC')->format('Y-m-d\TH:i:s.v\Z'),
            'level' => 'warning',
            'message' => Redactor::text($line, LogSchema::limit('message', 500)),
            'function' => JsonLogFormatter::function(),
            'stage' => JsonLogFormatter::stage(),
            'correlation_id' => Correlation::current(),
            'request_id' => is_string($_SERVER['LAMBDA_REQUEST_ID'] ?? null) ? $_SERVER['LAMBDA_REQUEST_ID'] : null,
            'actor' => 'system',
            'event' => LogEvent::RuntimeOutput->value,
        ]);
    }
}
