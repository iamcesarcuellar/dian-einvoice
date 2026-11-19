<?php

namespace App\Modules\Dian\Simulator;

use App\Support\Json;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client of the admin API of the local DIAN simulator (compose service simulator), a development
 * tool: it registers taxpayers (NIT, software, PIN and numbering ranges), sets failure modes and
 * reads the recent activity. Only the backend talks to it; the browser never does.
 */
final class DianSimulator
{
    /** Failure modes of the simulator, as its admin API names them. */
    public const array MODES = ['normal', 'reject', 'no_response', 'error_500', 'slow', 'intermittent'];

    public function __construct(
        private readonly string $url,
        private readonly string $token,
        private readonly float $timeout,
    ) {}

    /**
     * The failure mode that applies to the requests of a NIT.
     *
     * @return array<mixed>
     */
    public function mode(string $nit): array
    {
        return $this->json('get', "/admin/modes/{$nit}");
    }

    /**
     * @param  array{mode: string, rule?: string|null, delay_ms?: int|null, failure_rate?: int|null, count?: int|null}  $mode
     * @return array<mixed>
     */
    public function setMode(string $nit, array $mode): array
    {
        return $this->json('put', "/admin/modes/{$nit}", $mode);
    }

    /**
     * The validation rules the simulator implements: code, kind and message.
     *
     * @return list<array<mixed>>
     */
    public function rules(): array
    {
        return Json::objects($this->json('get', '/admin/rules'), 'rules');
    }

    /**
     * The last requests received from a NIT, newest first.
     *
     * @return list<array<mixed>>
     */
    public function requests(string $nit, int $limit = 50): array
    {
        return Json::objects($this->json('get', '/admin/requests', ['nit' => $nit, 'limit' => $limit]), 'requests');
    }

    /**
     * The documents the simulator processed for a NIT.
     *
     * @return list<array<mixed>>
     */
    public function documents(string $nit): array
    {
        return Json::objects($this->json('get', '/admin/documents', ['nit' => $nit]), 'documents');
    }

    /**
     * @param  array<string, mixed>  $registration
     */
    public function registerTaxpayer(string $nit, array $registration): void
    {
        $this->json('put', "/admin/taxpayers/{$nit}", $registration);
    }

    /** Removes a taxpayer with its documents, its mode and its activity. */
    public function forgetTaxpayer(string $nit): void
    {
        $this->json('delete', "/admin/taxpayers/{$nit}");
    }

    /** Clears every taxpayer, document, mode and request. */
    public function reset(): void
    {
        $this->json('post', '/admin/reset');
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
            throw new SimulatorUnavailable('The local DIAN simulator is not answering.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new SimulatorUnavailable(sprintf('The local DIAN simulator answered HTTP %d: %s', $response->status(), Json::text($response->json(), 'error') ?? 'no details'));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->url)
            ->withHeaders(['X-Simulator-Token' => $this->token])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(2)
            ->timeout($this->timeout);
    }
}
