<?php

namespace App\Modules\Developers\Receiver;

use App\Support\Json;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Client of the local webhook receiver, a development tool that lives in the simulator service: it
 * receives webhooks at {url}/{company id}, verifies their signature with the secrets registered
 * here, keeps the last ones with the result, and can answer HTTP 500 or slowly to the next N
 * deliveries. Only the backend talks to its admin API; the browser never does.
 */
final class LocalWebhookReceiver
{
    /** Modes of the receiver, as its admin API names them. */
    public const array MODES = ['normal', 'error_500', 'slow'];

    public function __construct(
        private readonly string $url,
        private readonly string $adminUrl,
        private readonly string $token,
        private readonly float $timeout,
    ) {}

    /** The URL a subscription of this company uses to reach the receiver, on the internal network. */
    public function urlFor(string $companyId): string
    {
        return rtrim($this->url, '/').'/'.$companyId;
    }

    /** Whether a subscription URL points to the local receiver, and then for which company. */
    public function companyOf(string $url): ?string
    {
        $base = rtrim($this->url, '/').'/';

        if (! str_starts_with(strtolower($url), strtolower($base))) {
            return null;
        }

        $rest = trim(substr($url, strlen($base)), '/');

        return preg_match('/^[0-9a-z]{26}$/i', $rest) === 1 ? strtolower($rest) : null;
    }

    /**
     * The mode and the last webhooks the receiver got for a company, newest first.
     *
     * @return array{mode: array<mixed>, received: list<array<mixed>>, secrets: int}
     */
    public function status(string $companyId, int $limit = 30): array
    {
        $status = $this->json('get', "/admin/webhook-receiver/{$companyId}", ['limit' => $limit]);
        $mode = $status['mode'] ?? null;

        return [
            'mode' => is_array($mode) ? $mode : ['mode' => 'normal', 'remaining' => null, 'delay_ms' => null],
            'received' => Json::objects($status, 'received'),
            'secrets' => Json::int($status, 'secrets') ?? 0,
        ];
    }

    /**
     * @param  array{mode: string, count?: int|null, delay_ms?: int|null}  $mode
     * @return array<mixed>
     */
    public function setMode(string $companyId, array $mode): array
    {
        return Json::array($this->json('put', "/admin/webhook-receiver/{$companyId}/mode", $mode), 'mode');
    }

    /** Tells the receiver the secret of a subscription, so it can verify what that one signs. */
    public function registerSecret(string $companyId, string $subscriptionId, #[SensitiveParameter] string $secret): void
    {
        $this->json('put', "/admin/webhook-receiver/{$companyId}/secrets/{$subscriptionId}", ['secret' => $secret]);
    }

    public function forgetSecret(string $companyId, string $subscriptionId): void
    {
        $this->json('delete', "/admin/webhook-receiver/{$companyId}/secrets/{$subscriptionId}");
    }

    /** Forgets a company: its secrets, its mode and what it received. */
    public function forget(string $companyId): void
    {
        $this->json('delete', "/admin/webhook-receiver/{$companyId}");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<mixed>
     */
    private function json(string $method, string $path, array $data = []): array
    {
        try {
            /** @var Response $response */
            $response = $this->client()->{$method}($path, $data);
        } catch (Throwable $exception) {
            throw new ReceiverUnavailable('The local webhook receiver is not answering.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new ReceiverUnavailable(sprintf('The local webhook receiver answered HTTP %d: %s', $response->status(), Json::text($response->json(), 'error') ?? 'no details'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->adminUrl)
            ->withHeaders(['X-Simulator-Token' => $this->token])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(2)
            ->timeout($this->timeout);
    }
}
