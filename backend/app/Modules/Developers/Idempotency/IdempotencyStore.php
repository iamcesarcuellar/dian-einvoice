<?php

namespace App\Modules\Developers\Idempotency;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency keys of POST requests, per company, in MySQL. reserve() inserts the key with the
 * unique index on (company_id, idempotency_key), so of two simultaneous requests only one gets the
 * reservation; the other finds the row and is told what to answer.
 */
final class IdempotencyStore
{
    public const string PROCESSING = 'processing';

    public const string COMPLETED = 'completed';

    /** Headers kept with a stored response. */
    private const array KEPT_HEADERS = ['Content-Type', 'Location'];

    /**
     * @return array{0: 'reserved'|'replay'|'mismatch'|'in_progress', 1: stdClass|null}
     */
    public function reserve(string $companyId, string $key, string $fingerprint, string $method, string $path): array
    {
        for ($try = 0; $try < 3; $try++) {
            $now = Carbon::now();
            $id = Str::lower((string) Str::ulid());

            $inserted = DB::table('idempotency_keys')->insertOrIgnore([
                'id' => $id,
                'company_id' => $companyId,
                'idempotency_key' => $key,
                'fingerprint' => $fingerprint,
                'method' => $method,
                'path' => Str::limit($path, 250, ''),
                'status' => self::PROCESSING,
                'locked_until' => $now->copy()->addSeconds($this->lease()),
                'expires_at' => $now->copy()->addHours($this->ttlHours()),
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return ['reserved', DB::table('idempotency_keys')->where('id', $id)->first()];
            }

            $row = DB::table('idempotency_keys')->where('company_id', $companyId)->where('idempotency_key', $key)->first();

            if ($row === null) {
                continue;
            }

            // Kept for 24 hours only: an expired key is free again.
            if (Carbon::parse($row->expires_at)->isPast()) {
                DB::table('idempotency_keys')->where('id', $row->id)->where('expires_at', '<', $now)->delete();

                continue;
            }

            if (! hash_equals($row->fingerprint, $fingerprint)) {
                return ['mismatch', $row];
            }

            if ($row->status === self::COMPLETED) {
                return ['replay', $row];
            }

            // The request that reserved it died without an answer: take the reservation over.
            if ($row->locked_until !== null && Carbon::parse($row->locked_until)->isPast()) {
                $taken = DB::table('idempotency_keys')
                    ->where('id', $row->id)
                    ->where('status', self::PROCESSING)
                    ->where('locked_until', '<', $now)
                    ->update(['locked_until' => $now->copy()->addSeconds($this->lease())]);

                if ($taken === 1) {
                    return ['reserved', $row];
                }
            }

            return ['in_progress', $row];
        }

        return ['in_progress', null];
    }

    /** Stores the answer of the request that held the reservation. */
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
            'locked_until' => null,
            'completed_at' => Carbon::now(),
        ]);
    }

    /** Frees the key after a server error, so the same request can be tried again. */
    public function release(string $id): void
    {
        DB::table('idempotency_keys')->where('id', $id)->where('status', self::PROCESSING)->delete();
    }

    /** Deletes up to $limit expired keys; returns how many. */
    public function prune(int $limit = 1000): int
    {
        return DB::table('idempotency_keys')->where('expires_at', '<', Carbon::now())->limit($limit)->delete();
    }

    private function lease(): int
    {
        return max(5, (int) config('developers.idempotency.lease_seconds', 60));
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('developers.idempotency.ttl_hours', 24));
    }
}
