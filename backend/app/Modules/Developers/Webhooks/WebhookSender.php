<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Developers\Enums\WebhookAttemptOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * One HTTP attempt of a webhook: POST of the stored body with the Standard Webhooks headers
 * (webhook-id, webhook-timestamp, webhook-signature), at most 5 seconds with the connection,
 * redirects not followed. Private destinations are refused unless allowed (local development).
 */
final class WebhookSender
{
    private const int SNIPPET = 1000;

    public function send(string $url, string $messageId, string $body, string $secret): SendResult
    {
        if (! (bool) config('developers.webhooks.allow_private_targets', true) && ($reason = PrivateTargets::reason($url)) !== null) {
            return new SendResult(WebhookAttemptOutcome::Blocked, 0, error: $reason);
        }

        $timestamp = time();
        $timeout = max(1.0, (float) config('developers.webhooks.timeout', 5));
        $started = hrtime(true);

        try {
            $response = Http::withHeaders([
                'webhook-id' => $messageId,
                'webhook-timestamp' => (string) $timestamp,
                'webhook-signature' => WebhookSignature::sign($secret, $messageId, $timestamp, $body),
                'User-Agent' => 'dian-einvoice-webhooks/1.0',
            ])
                ->withBody($body, 'application/json')
                ->connectTimeout(min(3.0, $timeout))
                ->timeout($timeout)
                ->withoutRedirecting()
                ->post($url);
        } catch (ConnectionException $exception) {
            $message = $exception->getMessage();
            $timedOut = str_contains($message, 'cURL error 28') || Str::contains(Str::lower($message), 'timed out');

            return new SendResult(
                $timedOut ? WebhookAttemptOutcome::Timeout : WebhookAttemptOutcome::ConnectionError,
                self::elapsed($started),
                error: Str::limit($timedOut ? sprintf('No answer within %s seconds.', rtrim(rtrim(number_format($timeout, 1), '0'), '.')) : $message, 497),
            );
        } catch (Throwable $exception) {
            return new SendResult(WebhookAttemptOutcome::ConnectionError, self::elapsed($started), error: Str::limit($exception->getMessage(), 497));
        }

        $status = $response->status();
        $succeeded = $status >= 200 && $status < 300;

        return new SendResult(
            $succeeded ? WebhookAttemptOutcome::Succeeded : WebhookAttemptOutcome::HttpError,
            self::elapsed($started),
            statusCode: $status,
            error: $succeeded ? null : sprintf('The endpoint answered HTTP %d.', $status),
            snippet: Str::limit($response->body(), self::SNIPPET, '…'),
        );
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
