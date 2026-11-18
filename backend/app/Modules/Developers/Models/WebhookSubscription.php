<?php

namespace App\Modules\Developers\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where a company wants its events: a URL, the event types it receives and the secret that signs
 * every delivery (Standard Webhooks). The secret is stored encrypted, since it signs, and shown
 * only when it is created or rotated.
 *
 * @property list<string> $events
 */
class WebhookSubscription extends Model
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['url', 'description', 'events', 'is_active', 'secret', 'secret_rotated_at', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'secret' => 'encrypted',
            'secret_rotated_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'datetime',
            'last_delivery_at' => 'datetime',
            'last_response_code' => 'integer',
        ];
    }

    public function listensTo(string $type): bool
    {
        return in_array($type, $this->events ?? [], true);
    }

    /** active; inactive when switched off by hand; disabled when switched off after failures. */
    public function status(): string
    {
        return match (true) {
            $this->is_active => 'active',
            $this->disabled_at !== null => 'disabled',
            default => 'inactive',
        };
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'subscription_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
