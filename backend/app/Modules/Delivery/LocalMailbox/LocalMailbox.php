<?php

namespace App\Modules\Delivery\LocalMailbox;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client of the API of the local development mailbox (compose service mail, Mailpit), a development
 * tool that keeps every email the app sends and relays none. The app sends through SMTP only; this
 * client is for the tools around it: make seed clears the mailbox, delivery:check reads it and the
 * Settings → DIAN card shows its address and how many emails it holds.
 */
final class LocalMailbox
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $webUrl,
        private readonly float $timeout,
    ) {}

    /** Address of its web interface, for people. */
    public function url(): string
    {
        return $this->webUrl;
    }

    /**
     * Whether it answers, how many emails it holds and its version.
     *
     * @return array{reachable: bool, messages: int|null, version: string|null}
     */
    public function status(): array
    {
        try {
            $messages = $this->json('get', '/api/v1/messages', ['limit' => 1]);
            $info = $this->json('get', '/api/v1/info');

            return [
                'reachable' => true,
                'messages' => (int) ($messages['total'] ?? 0),
                'version' => isset($info['Version']) ? (string) $info['Version'] : null,
            ];
        } catch (LocalMailboxUnavailable) {
            return ['reachable' => false, 'messages' => null, 'version' => null];
        }
    }

    /** Deletes every email it holds. */
    public function clear(): void
    {
        $this->json('delete', '/api/v1/messages', ['IDs' => []]);
    }

    /**
     * Summaries of the emails that match a Mailpit search, such as to:"a@b.example", newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 50): array
    {
        return $this->json('get', '/api/v1/search', ['query' => $query, 'limit' => $limit])['messages'] ?? [];
    }

    /**
     * One email with its headers, bodies and the list of its attachments.
     *
     * @return array<string, mixed>
     */
    public function message(string $id): array
    {
        return $this->json('get', '/api/v1/message/'.rawurlencode($id));
    }

    /** The bytes of one part of an email, such as an attachment. */
    public function part(string $id, string $partId): string
    {
        return $this->send('get', '/api/v1/message/'.rawurlencode($id).'/part/'.rawurlencode($partId))->body();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $data = []): array
    {
        $response = $this->send($method, $path, $data);

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data = []): Response
    {
        try {
            /** @var Response $response */
            $response = $this->client()->{$method}($path, $data);
        } catch (Throwable $exception) {
            throw new LocalMailboxUnavailable('The local mailbox is not answering.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new LocalMailboxUnavailable(sprintf('The local mailbox answered HTTP %d.', $response->status()));
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->apiUrl)
            ->acceptJson()
            ->connectTimeout(2)
            ->timeout($this->timeout);
    }
}
