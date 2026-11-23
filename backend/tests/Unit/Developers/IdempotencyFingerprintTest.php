<?php

namespace Tests\Unit\Developers;

use App\Modules\Developers\Idempotency\IdempotencyFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * The fingerprint decides whether a repeated Idempotency-Key is the same request (its answer is
 * replayed) or another one (422). Too loose, and a different invoice gets the first one's response;
 * too strict, and a client that re-serializes its JSON gets a 422.
 */
final class IdempotencyFingerprintTest extends TestCase
{
    public function test_the_same_json_written_another_way_is_the_same_request(): void
    {
        $compact = IdempotencyFingerprint::of('POST', '/api/v1/invoices', '{"contact_id":"c1","lines":[{"quantity":"1","unit_price":"100"}]}');
        $reordered = IdempotencyFingerprint::of('POST', '/api/v1/invoices', "{\n  \"lines\": [ { \"unit_price\": \"100\", \"quantity\": \"1\" } ],\n  \"contact_id\": \"c1\"\n}");

        $this->assertSame($compact, $reordered);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $compact);
    }

    public function test_any_change_of_method_path_body_or_list_order_is_another_request(): void
    {
        $base = IdempotencyFingerprint::of('POST', '/api/v1/invoices', '{"lines":[{"id":1},{"id":2}]}');

        $this->assertNotSame($base, IdempotencyFingerprint::of('POST', '/api/v1/credit-notes', '{"lines":[{"id":1},{"id":2}]}'));
        $this->assertNotSame($base, IdempotencyFingerprint::of('PUT', '/api/v1/invoices', '{"lines":[{"id":1},{"id":2}]}'));
        $this->assertNotSame($base, IdempotencyFingerprint::of('POST', '/api/v1/invoices', '{"lines":[{"id":2},{"id":1}]}'));
        $this->assertNotSame($base, IdempotencyFingerprint::of('POST', '/api/v1/invoices', '{"lines":[{"id":1},{"id":"2"}]}'));
    }

    public function test_a_body_that_is_not_json_is_compared_byte_for_byte(): void
    {
        $this->assertSame(IdempotencyFingerprint::of('POST', '/p', 'a=1&b=2'), IdempotencyFingerprint::of('POST', '/p', 'a=1&b=2'));
        $this->assertNotSame(IdempotencyFingerprint::of('POST', '/p', 'a=1&b=2'), IdempotencyFingerprint::of('POST', '/p', 'b=2&a=1'));
        $this->assertNotSame(IdempotencyFingerprint::of('POST', '/p', ''), IdempotencyFingerprint::of('POST', '/p', '{}'));
    }
}
