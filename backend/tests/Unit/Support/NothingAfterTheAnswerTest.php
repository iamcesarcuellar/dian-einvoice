<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * AWS freezes the execution environment of a function as soon as it answers, so nothing may wait for after
 * the answer: no work after the response is sent, no deferred callback, no process left in the background.
 * Locally such work would run, and in AWS it would stop half done until the next invocation, if any. The
 * outbox publishes its events before the answer (OutboxFastPath); this test keeps everything else so.
 */
final class NothingAfterTheAnswerTest extends TestCase
{
    /** What would leave work for after the answer, and why each one is refused. */
    private const array REFUSED = [
        '/->afterResponse\(/' => 'a dispatch after the response',
        '/dispatchAfterResponse\(/' => 'a dispatch after the response',
        '/\bdefer\(/' => 'a deferred callback, which runs after the response',
        '/->terminating\(/' => 'a callback of the termination of the application',
        '/register_shutdown_function\(/' => 'a callback of the end of the process',
        '/fastcgi_finish_request\(/' => 'an answer sent before the work ends',
        '/ignore_user_abort\(/' => 'work that goes on after the client left',
        '/->runInBackground\(/' => 'a scheduled task in a process of its own, which outlives the invocation',
        '/pcntl_fork\(/' => 'a child process, except in the development tools that wait for theirs',
    ];

    /** Development tools that fork and wait for every child before they return. */
    private const array FORKING_TOOLS = [
        'app/Modules/Billing/Console/StressNumberingCommand.php',
        'app/Modules/Payments/Console/PaymentsCheckCommand.php',
    ];

    public function test_the_application_leaves_nothing_for_after_its_answer(): void
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (['app', 'bootstrap', 'config', 'database', 'routes'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/{$directory}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                $path = substr($file->getPathname(), strlen($root) + 1);

                if (! str_ends_with($path, '.php') || str_starts_with($path, 'bootstrap/cache/')) {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                foreach (self::REFUSED as $pattern => $reason) {
                    if (preg_match($pattern, $code) === 1 && ! ($pattern === '/pcntl_fork\(/' && in_array($path, self::FORKING_TOOLS, true))) {
                        $found[] = "{$path}: {$reason}";
                    }
                }
            }
        }

        $this->assertSame([], $found);
    }
}
