<?php

namespace App\Support\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An event waiting to be published. Not scoped to the current company: the relay reads them all.
 *
 * @property array<string, mixed> $payload
 */
class OutboxEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['company_id', 'aggregate_type', 'aggregate_id', 'type', 'payload', 'available_at', 'published_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** A field of the payload as text, or null when it is missing or not a scalar. */
    public function payloadString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** A field of the payload as a whole number, or null when it is missing or not numeric. */
    public function payloadInt(string $key): ?int
    {
        $value = $this->payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}
