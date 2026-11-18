<?php

namespace App\Modules\Developers\Webhooks;

/**
 * Attempts of a delivery: the first plus one per wait, with the waits of the rest of the chain
 * (2, 5, 15, 45 and 120 s plus jitter unless WEBHOOK_RETRY_DELAYS says otherwise).
 */
final class WebhookRetryPolicy
{
    public function maxAttempts(): int
    {
        return 1 + count($this->delays());
    }

    /** Seconds to wait after attempt $attempt of a run failed, with a random share of the jitter. */
    public function delay(int $attempt): int
    {
        $delays = $this->delays();
        $base = $delays[max(0, min($attempt, count($delays)) - 1)] ?? 2;
        $jitter = max(0.0, (float) config('developers.webhooks.retry_jitter', 0.2));

        return max(1, (int) round($base * (1 + $jitter * mt_rand() / mt_getrandmax())));
    }

    /** Seconds an attempt may still be running: the timeout plus a margin. */
    public function lease(): int
    {
        return (int) ceil((float) config('developers.webhooks.timeout', 5)) + 10;
    }

    /** Failed deliveries in a row that switch a subscription off. */
    public function disableAfter(): int
    {
        return max(1, (int) config('developers.webhooks.disable_after_failures', 20));
    }

    /**
     * @return list<int>
     */
    private function delays(): array
    {
        $delays = config('developers.webhooks.retry_delays', []);

        return is_array($delays) && $delays !== [] ? array_values($delays) : [2, 5, 15, 45, 120];
    }
}
