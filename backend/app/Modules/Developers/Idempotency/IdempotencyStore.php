<?php

namespace App\Modules\Developers\Idempotency;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency keys of POST requests, per company, in MySQL. reserve() finds the row of a key or
 * inserts it, committed at once and still "processing"; the request then runs inside a transaction
 * that first locks that row (claim(), FOR UPDATE NOWAIT) and ends by writing the answer into it
 * (complete()), so the business effect and the completed key are committed together or not at all.
 *
 * The lock is what says a request is still running: a "processing" row that nobody locks was left
 * by a request that died before committing, so nothing it did is committed and another request may
 * take it over. A row whose holder is alive cannot be claimed (409), and a completed one is replayed.
 */
final class IdempotencyStore
{
    public const string PROCESSING = 'processing';

    public const string COMPLETED = 'completed';

    /** Headers kept with a stored response. */
    private const array KEPT_HEADERS = ['Content-Type', 'Location'];

    /** MySQL: the row is locked and NOWAIT asked not to wait for it. */
    private const int LOCK_NOWAIT = 3572;

    /**
     * The row of the key: 'reserved' when it is processing (new, or left by a request that may be
     * gone: claim() decides), 'replay' when it is completed, 'mismatch' when it belongs to another
     * request. Reading first never waits for the lock of a running request.
     *
     * @return array{0: 'reserved'|'replay'|'mismatch'|'in_progress', 1: stdClass|null}
     */
    public function reserve(string $companyId, string $key, string $fingerprint, string $method, string $path): array
    {
        for ($try = 0; $try < 3; $try++) {
            $now = Carbon::now();
            $row = $this->row($companyId, $key);

            if ($row === null) {
                $id = Str::lower((string) Str::ulid());

                $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                    'id' => $id,
                    'company_id' => $companyId,
                    'idempotency_key' => $key,
                    'fingerprint' => $fingerprint,
                    'method' => $method,
                    'path' => Str::limit($path, 250, ''),
                    'status' => self::PROCESSING,
                    'expires_at' => $now->copy()->addHours($this->ttlHours()),
                    'created_at' => $now,
                ]);

                if ($inserted === 1) {
                    return ['reserved', DB::table('idempotency_keys')->where('id', $id)->first()];
                }

                // Another request inserted it meanwhile: read it again.
                continue;
            }

            // Kept for 24 hours only: an expired key is free again. Columns of a row read as text.
            if (is_string($row->expires_at) && Carbon::parse($row->expires_at)->isPast()) {
                DB::table('idempotency_keys')->where('id', $row->id)->where('expires_at', '<', $now)->delete();

                continue;
            }

            if (! is_string($row->fingerprint) || ! hash_equals($row->fingerprint, $fingerprint)) {
                return ['mismatch', $row];
            }

            return [$row->status === self::COMPLETED ? 'replay' : 'reserved', $row];
        }

        return ['in_progress', null];
    }

    /**
     * Inside the transaction of the request: locks its row without waiting. 'claimed' when this
     * request holds it now; 'in_progress' when another request holds it; 'completed' when it was
     * completed meanwhile (replay it); 'gone' when a request that failed freed it meanwhile.
     *
     * @return 'claimed'|'in_progress'|'completed'|'gone'
     */
    public function claim(string $id): string
    {
        try {
            $row = DB::selectOne('select status from idempotency_keys where id = ? for update nowait', [$id]);
        } catch (QueryException $exception) {
            $code = $exception->errorInfo[1] ?? null;

            if (is_numeric($code) && (int) $code === self::LOCK_NOWAIT) {
                return 'in_progress';
            }

            throw $exception;
        }

        return match (true) {
            ! $row instanceof stdClass => 'gone',
            $row->status === self::COMPLETED => 'completed',
            default => 'claimed',
        };
    }

    /** The row of a key, by its id. */
    public function find(string $id): ?stdClass
    {
        $row = DB::table('idempotency_keys')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /** Stores the answer, inside the transaction of the request that holds the row. */
    public function complete(string $id, Response $response): void
    {
        $headers = [];

        foreach (self::KEPT_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        $content = $response->getContent();

        DB::table('idempotency_keys')->where('id', $id)->update([
            'status' => self::COMPLETED,
            'response_status' => $response->getStatusCode(),
            'response_headers' => json_encode($headers, JSON_THROW_ON_ERROR),
            'response_body' => $content === false ? '' : $content,
            'completed_at' => Carbon::now(),
        ]);
    }

    /** Frees the key after a server error, once the transaction of the request is rolled back. */
    public function release(string $id): void
    {
        DB::table('idempotency_keys')->where('id', $id)->where('status', self::PROCESSING)->delete();
    }

    /** Deletes up to $limit expired keys; returns how many. */
    public function prune(int $limit = 1000): int
    {
        return DB::table('idempotency_keys')->where('expires_at', '<', Carbon::now())->limit($limit)->delete();
    }

    private function row(string $companyId, string $key): ?stdClass
    {
        $row = DB::table('idempotency_keys')->where('company_id', $companyId)->where('idempotency_key', $key)->first();

        return $row instanceof stdClass ? $row : null;
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('developers.idempotency.ttl_hours', 24));
    }
}
