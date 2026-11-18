<?php

namespace App\Modules\Developers\Models;

use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\BelongsToCompany;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An API key of a company: the credential of an integration, as the user of the requests it signs.
 * Only the SHA-256 of the key is stored; public_id, after the prefix, is the visible identifier.
 * Keys are revoked, never deleted, so timelines keep the name of the key that acted.
 *
 * @property ApiKeyLevel $level
 */
class ApiKey extends Model implements Authenticatable
{
    use BelongsToCompany, HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'level', 'public_id', 'secret_hash', 'rate_limit_per_minute', 'expires_at', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['secret_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => ApiKeyLevel::class,
            'rate_limit_per_minute' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** dei_ and the public id: what the web app shows and the logs may name. */
    public function visibleId(): string
    {
        return config('developers.api_keys.prefix').$this->public_id;
    }

    /** active, expired or revoked. */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    /** Requests per minute: its own limit or the default one. */
    public function rateLimit(): int
    {
        return $this->rate_limit_per_minute ?? max(1, (int) config('developers.api_keys.rate_limit_per_minute', 120));
    }

    /** Records the use at most once a minute, without touching updated_at. */
    public function touchLastUsed(): void
    {
        if ($this->last_used_at !== null && $this->last_used_at->gt(Carbon::now()->subMinute())) {
            return;
        }

        $this->newQueryWithoutScopes()->whereKey($this->getKey())->toBase()->update(['last_used_at' => Carbon::now()]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // An API key authenticates requests through the api-key guard; it has no password or session.

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
