<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Runs a task in several forked processes that start at the same instant, each with its own
 * database and Redis connections, as concurrent requests or workers would: what the tests of
 * numbering, balances, payments and idempotency need. A child never runs the shutdown of the test
 * runner: it writes its outcome to a file and ends itself with SIGKILL.
 */
final class Parallel
{
    /**
     * Starts the task in one forked process and returns its pid at once, for a test that stops it
     * midway (kill()), as a worker that dies during a call.
     *
     * @param  callable(): void  $task
     */
    public static function spawn(callable $task): int
    {
        DB::disconnect();
        Redis::purge();
        Redis::purge('cache');
        self::releaseFreeMemory();

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('Could not start a process.');
        }

        if ($pid === 0) {
            try {
                $task();
            } catch (Throwable) {
                // The parent judges by what reached the database.
            }

            DB::disconnect();
            posix_kill(posix_getpid(), SIGKILL);
        }

        return $pid;
    }

    /** Ends a process started with spawn() at once, as a worker killed by its host. */
    public static function kill(int $pid): void
    {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }

    /**
     * @template T
     *
     * @param  callable(int): T  $task  Receives the index of its process.
     * @return list<array{ok: bool, value: T|null, error: string|null, exception: class-string<Throwable>|null}> One outcome per process, in order.
     */
    public static function run(int $processes, callable $task): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new LogicException('The pcntl extension is missing.');
        }

        // A forked child must never share the parent's connections.
        DB::disconnect();
        Redis::purge();
        Redis::purge('cache');
        self::releaseFreeMemory();

        $directory = sys_get_temp_dir().'/parallel-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $startAt = microtime(true) + 1.0;
        $children = [];

        for ($index = 0; $index < $processes; $index++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('Could not start a process.');
            }

            if ($pid === 0) {
                // Connect first, then wait: every process reaches the database at the same instant.
                DB::connection()->getPdo();
                usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

                try {
                    $outcome = ['ok' => true, 'value' => $task($index), 'error' => null, 'exception' => null];
                } catch (Throwable $exception) {
                    $outcome = ['ok' => false, 'value' => null, 'error' => $exception->getMessage(), 'exception' => $exception::class];
                }

                file_put_contents("{$directory}/{$index}", serialize($outcome));
                DB::disconnect();
                posix_kill(posix_getpid(), SIGKILL);
            }

            $children[$index] = $pid;
        }

        $outcomes = [];

        foreach ($children as $index => $pid) {
            pcntl_waitpid($pid, $status);
            $file = "{$directory}/{$index}";
            $outcomes[] = is_file($file)
                ? unserialize((string) file_get_contents($file))
                : ['ok' => false, 'value' => null, 'error' => 'The process ended without an outcome.', 'exception' => null];
            @unlink($file);
        }

        @rmdir($directory);

        /** @var list<array{ok: bool, value: T|null, error: string|null, exception: class-string<Throwable>|null}> $outcomes */
        return $outcomes;
    }

    /**
     * Hands back to the system the memory that PHP's memory manager keeps free for reuse, before
     * the test runner forks. A child shares the parent's pages until it writes to one, and then
     * gets a copy of its own: free memory the parent keeps counts against the container's memory
     * limit once in the parent and again in every child that reuses it. Collecting cycles first
     * does not help: the slots it frees spread each child's allocations over more shared pages.
     */
    private static function releaseFreeMemory(): void
    {
        gc_mem_caches();
    }
}
