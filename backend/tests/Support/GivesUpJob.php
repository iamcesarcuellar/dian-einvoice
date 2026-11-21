<?php

namespace Tests\Support;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/** A queued job that gives up for good on its first run, as a step of the chain does after its last attempt. */
final class GivesUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $this->fail(new RuntimeException('The transmission of DRL7 failed. Attempt 3 of 3: the DIAN answered HTTP 500.'));
    }
}
