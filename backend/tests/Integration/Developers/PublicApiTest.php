<?php

namespace Tests\Integration\Developers;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Idempotency\IdempotencyFingerprint;
use App\Modules\Developers\Idempotency\IdempotencyStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\IntegrationTestCase;
use Tests\Support\Parallel;

/**
 * The public API as an integration sees it: API keys, read-only keys, the rate limit and
 * Idempotency-Key, over HTTP.
 */
final class PublicApiTest extends IntegrationTestCase
{
    public function test_only_an_active_key_signs_in(): void
    {
        $company = $this->company();
        $keys = app(ApiKeys::class);
        [, $valid] = $keys->create('Synthetic valid key', ApiKeyLevel::ReadWrite);
        [$revokedKey, $revoked] = $keys->create('Synthetic revoked key', ApiKeyLevel::ReadWrite);
        $keys->revoke($revokedKey, $company->user);
        [, $expired] = $keys->create('Synthetic expired key', ApiKeyLevel::ReadWrite, Carbon::now()->subMinute());

        $this->keyed($valid)->getJson('/api/v1/invoices')->assertOk();
        $this->keyed($revoked)->getJson('/api/v1/invoices')->assertUnauthorized()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'revoked'));
        $this->keyed($expired)->getJson('/api/v1/invoices')->assertUnauthorized()->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'expired'));
        $this->keyed('dei_'.str_repeat('a', 12).'_'.str_repeat('b', 40))->getJson('/api/v1/invoices')->assertUnauthorized();
        // A key never reaches the routes of the web app.
        $this->keyed($valid)->getJson('/api/v1/dashboard')->assertForbidden();
    }

    public function test_a_read_only_key_reads_and_cannot_write(): void
    {
        $company = $this->company();
        [, $key] = app(ApiKeys::class)->create('Synthetic read-only key', ApiKeyLevel::ReadOnly);

        $this->keyed($key)->getJson('/api/v1/invoices')->assertOk();
        $this->keyed($key)->postJson('/api/v1/invoices', ['contact_id' => $company->customer()->id])->assertForbidden();
        $this->assertSame(0, Document::query()->count());
    }

    public function test_above_its_limit_a_key_gets_429_with_retry_after(): void
    {
        $this->company();
        [, $key] = app(ApiKeys::class)->create('Synthetic limited key', ApiKeyLevel::ReadWrite, rateLimit: 2);

        $this->keyed($key)->getJson('/api/v1/invoices')->assertOk()->assertHeader('X-RateLimit-Limit', '2')->assertHeader('X-RateLimit-Remaining', '1');
        $this->keyed($key)->getJson('/api/v1/invoices')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
        $limited = $this->keyed($key)->getJson('/api/v1/invoices')->assertStatus(429);

        $this->assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
    }

    public function test_an_idempotency_key_replays_refuses_another_request_and_a_running_one(): void
    {
        $company = $this->company();
        [, $key] = app(ApiKeys::class)->create('Synthetic integration key', ApiKeyLevel::ReadWrite);
        $contact = $company->customer()->id;
        $post = fn (string $idempotency, string $body) => $this->keyed($key)->call('POST', '/api/v1/invoices', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer {$key}",
            'HTTP_IDEMPOTENCY_KEY' => $idempotency,
        ], $body);

        $first = $post('synthetic-key-1', json_encode(['contact_id' => $contact, 'notes' => 'Synthetic draft'], JSON_THROW_ON_ERROR));
        $first->assertCreated();
        // The same request, with its JSON written another way: the kept answer, and nothing runs again.
        $post('synthetic-key-1', "{ \"notes\": \"Synthetic draft\",\n \"contact_id\": \"{$contact}\" }")
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $post('synthetic-key-1', json_encode(['contact_id' => $contact, 'notes' => 'Another synthetic draft'], JSON_THROW_ON_ERROR))->assertStatus(422);

        // A request with this key is still running elsewhere.
        $body = json_encode(['contact_id' => $contact], JSON_THROW_ON_ERROR);
        app(IdempotencyStore::class)->reserve($company->company->id, 'synthetic-key-2', IdempotencyFingerprint::of('POST', '/api/v1/invoices', $body), 'POST', '/api/v1/invoices');
        $post('synthetic-key-2', $body)->assertStatus(409);

        $this->assertSame(1, Document::query()->where('status', DocumentStatus::Draft)->count());
    }

    public function test_simultaneous_requests_with_one_idempotency_key_create_one_document(): void
    {
        $company = $this->company();
        [, $key] = app(ApiKeys::class)->create('Synthetic integration key', ApiKeyLevel::ReadWrite);
        $body = ['contact_id' => $company->customer()->id, 'notes' => 'Synthetic simultaneous draft'];

        $outcomes = Parallel::run(6, fn (): array => $this->answer($company, $key, $body));

        $answers = array_column($outcomes, 'value');
        $this->assertCount(6, array_filter($answers));
        $created = array_values(array_filter($answers, fn (?array $answer): bool => $answer !== null && $answer['status'] === 201));
        $this->assertNotEmpty($created);
        $this->assertSame([], array_values(array_filter($answers, fn (?array $answer): bool => $answer !== null && ! in_array($answer['status'], [201, 409], true))));
        $this->assertCount(1, array_unique(array_column($created, 'id')));
        $this->assertSame(1, Document::query()->count());
    }

    /**
     * The next request signs with the API key. The guards are forgotten first: in a test they
     * would otherwise keep the key of the previous request, which a real request never does.
     *
     * @return $this
     */
    private function keyed(string $key): static
    {
        Auth::forgetGuards();

        return $this->withToken($key);
    }

    /**
     * @param  array<string, string>  $body
     * @return array{status: int, id: string|null}
     */
    private function answer(TemporaryCompany $company, string $key, array $body): array
    {
        $response = $this->keyed($key)->withHeaders(['Idempotency-Key' => 'synthetic-simultaneous'])->postJson('/api/v1/invoices', $body);

        $id = $response->json('data.id');

        return ['status' => $response->status(), 'id' => is_string($id) ? $id : null];
    }
}
