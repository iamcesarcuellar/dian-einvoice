<?php

namespace App\Support\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An event waiting to be published. Not scoped to the current company: the relay reads them all.
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
}
