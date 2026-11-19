<?php

namespace Tests\Integration\Developers;

use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Tools\TemporaryCompany;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Idempotency\IdempotencyFingerprint;
use App\Modules\Developers\Idempotency\IdempotencyStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\IntegrationTestCase;

/**
 * An Idempotency-Key and the effect of its request are committed in one transaction. So a reserved
 * key whose request died has nothing committed and is taken over by the next request; a key whose
 * request is still running (it holds the lock of its row) answers 409 however old it is; and a
 * failure between the effect and the completed key leaves neither.
 */
final class IdempotencyTest extends IntegrationTestCase
{
    public function test_a_reservation_left_by_a_dead_request_is_taken_over_once(): void
    {
        [$company, $key] = $this->keyedCompany();
        $body = $this->body($company);

        // What a request that died before its commit leaves: the row, processing, locked by nobody.
        $this->reserve($company, 'synthetic-abandoned', $body);

        $first = $this->postKeyed($key, 'synthetic-abandoned', $body)->assertCreated();
        $this->postKeyed($key, 'synthetic-abandoned', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, Document::query()->count());
    }

    public function test_a_reservation_whose_request_still_runs_answers_409(): void
    {
        [$company, $key] = $this->keyedCompany();
        $body = $this->body($company);
        $row = $this->reserve($company, 'synthetic-running', $body);

        // Another connection holds the row, as the transaction of a running request does.
        config(['database.connections.holder' => config('database.connections.mysql')]);
        $holder = DB::connection('holder');
        $holder->beginTransaction();
        $holder->select('select id from idempotency_keys where id = ? for update', [$row->id]);

        try {
            $this->postKeyed($key, 'synthetic-running', $body)->assertStatus(409)->assertHeader('Content-Type', 'application/problem+json');
            $this->assertSame(0, Document::query()->count());
        } finally {
            $holder->rollBack();
            DB::purge('holder');
        }

        $this->postKeyed($key, 'synthetic-running', $body)->assertCreated();
        $this->assertSame(1, Document::query()->count());
    }

    public function test_a_failure_between_the_effect_and_the_key_commits_neither(): void
    {
        [$company, $key] = $this->keyedCompany();
        $body = $this->body($company);
        $failure = new class
        {
            public bool $on = true;
        };

        // The request has created its draft; storing its answer in the key fails.
        DB::connection()->beforeExecuting(function (string $query) use ($failure): void {
            if ($failure->on && str_starts_with($query, 'update `idempotency_keys` set `status`')) {
                throw new RuntimeException('Synthetic failure before the key is completed.');
            }
        });

        $this->postKeyed($key, 'synthetic-failing', $body)->assertStatus(500);
        $this->assertSame(0, Document::query()->count(), 'The draft of a request whose key was not completed stayed.');
        $this->assertNull(DB::table('idempotency_keys')->where('idempotency_key', 'synthetic-failing')->first(), 'The key was not freed.');

        $failure->on = false;
        $this->postKeyed($key, 'synthetic-failing', $body)->assertCreated();
        $this->postKeyed($key, 'synthetic-failing', $body)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Document::query()->count());
    }

    /**
     * @return array{0: TemporaryCompany, 1: string}
     */
    private function keyedCompany(): array
    {
        $company = $this->company();
        [, $key] = app(ApiKeys::class)->create('Synthetic idempotency key', ApiKeyLevel::ReadWrite);

        return [$company, $key];
    }

    private function body(TemporaryCompany $company): string
    {
        return json_encode(['contact_id' => $company->customer()->id, 'notes' => 'Synthetic idempotent draft'], JSON_THROW_ON_ERROR);
    }

    private function reserve(TemporaryCompany $company, string $idempotencyKey, string $body): stdClass
    {
        [$outcome, $row] = app(IdempotencyStore::class)->reserve($company->company->id, $idempotencyKey, IdempotencyFingerprint::of('POST', '/api/v1/invoices', $body), 'POST', '/api/v1/invoices');
        $this->assertSame('reserved', $outcome);
        $this->assertInstanceOf(stdClass::class, $row);

        return $row;
    }

    /**
     * @return TestResponse<Response>
     */
    private function postKeyed(string $key, string $idempotencyKey, string $body): TestResponse
    {
        Auth::forgetGuards();

        return $this->call('POST', '/api/v1/invoices', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => "Bearer {$key}",
            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
        ], $body);
    }
}
