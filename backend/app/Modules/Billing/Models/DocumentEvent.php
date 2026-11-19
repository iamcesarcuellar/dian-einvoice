<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Developers\Models\ApiKey;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use App\Modules\Identity\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of the document timeline.
 *
 * @property string|null $actor_api_key_id Added with the API keys (Developers module).
 */
class DocumentEvent extends Model
{
    use BelongsToCompany, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['document_id', 'type', 'payload', 'occurred_at', 'actor_id', 'actor_api_key_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DocumentEventType::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // An entry written while a request signed with an API key acts, without a user, names the key.
        static::creating(function (self $event): void {
            if ($event->actor_id === null && $event->actor_api_key_id === null) {
                $event->actor_api_key_id = app(CurrentCompany::class)->apiKey()?->id;
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The API key that acted, revoked ones included.
     *
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'actor_api_key_id');
    }

    /**
     * Who acted, for the timeline: a user, an API key with its name, or null for the system.
     *
     * @return array{type: string, id: string, name: string}|null
     */
    public function actorSummary(): ?array
    {
        if ($this->actor_id !== null && $this->relationLoaded('actor') && $this->actor !== null) {
            return ['type' => 'user', 'id' => $this->actor->id, 'name' => $this->actor->name];
        }

        if ($this->actor_api_key_id !== null && $this->relationLoaded('apiKey') && $this->apiKey !== null) {
            return ['type' => 'api_key', 'id' => $this->apiKey->id, 'name' => $this->apiKey->name];
        }

        return null;
    }
}
