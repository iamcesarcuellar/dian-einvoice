<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Developers\Enums\WebhookAttemptOutcome;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SensitiveParameter;
use Throwable;

/**
 * One HTTP attempt of a webhook: POST of the stored body with the Standard Webhooks headers
 * (webhook-id, webhook-timestamp, webhook-signature), at most 5 seconds with the connection,
 * redirects not followed. In production only https endpoints are called (WEBHOOK_REQUIRE_HTTPS).
 * Private destinations are refused unless allowed (local development): the host is resolved and
 * checked here, and the connection goes to that address only. Of the answer, at most 64 KB are
 * read into memory; the attempt keeps its first 1000 characters.
 */
final class WebhookSender
{
    private const int SNIPPET = 1000;

    /** Bytes of the answer read into memory; the rest is discarded as it arrives. */
    public const int MAX_ANSWER_BYTES = 64 * 1024;

    /** The only port webhooks go to where https is required: the only one the functions may open toward the internet. */
    public const int HTTPS_PORT = 443;

    /** Why a webhook URL on another port is refused, when it is saved and before each attempt. */
    public const string HTTPS_PORT_ONLY = 'Webhook URLs must use port 443, the default port of https: deliveries leave on no other port.';

    public function send(string $url, string $messageId, string $body, #[SensitiveParameter] string $secret): SendResult
    {
        if ((bool) config('developers.webhooks.require_https', false) && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return new SendResult(WebhookAttemptOutcome::Blocked, 0, error: 'Only https endpoints receive webhooks here.');
        }

        if ((bool) config('developers.webhooks.require_https', false) && PrivateTargets::port($url) !== self::HTTPS_PORT) {
            return new SendResult(WebhookAttemptOutcome::Blocked, 0, error: self::HTTPS_PORT_ONLY);
        }

        $curl = [];

        if (! (bool) config('developers.webhooks.allow_private_targets', false)) {
            $target = PrivateTargets::inspect($url);
            $address = $target['addresses'][0] ?? null;

            if ($target['reason'] !== null || $address === null) {
                return new SendResult(WebhookAttemptOutcome::Blocked, 0, error: $target['reason'] ?? 'The URL has no public address.');
            }

            // The address just checked, and no new DNS answer.
            $curl[CURLOPT_RESOLVE] = [PrivateTargets::pin($target['host'], $target['port'], $address)];
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
                ->withOptions(['sink' => self::cappedSink(), 'curl' => $curl])
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

    /**
     * Where the answer is written: the first MAX_ANSWER_BYTES are kept, the rest is accepted and
     * dropped, so a huge answer neither fills the memory of the worker nor fails the transfer.
     */
    private static function cappedSink(): FnStream
    {
        $kept = Utils::tryFopen('php://temp', 'w+b');
        $stream = Utils::streamFor($kept);
        $written = 0;

        return FnStream::decorate($stream, [
            'write' => function (string $data) use ($stream, &$written): int {
                $room = self::MAX_ANSWER_BYTES - $written;

                if ($room > 0) {
                    $written += $stream->write(substr($data, 0, $room));
                }

                return strlen($data);
            },
        ]);
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
