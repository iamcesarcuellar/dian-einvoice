<?php

namespace App\Modules\Developers\Auth;

use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Models\ApiKey;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CompanyScope;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * API keys of the current company: created with a random secret shown once, found by their public
 * id and checked against the stored SHA-256 in constant time, and revoked.
 *
 * A key reads dei_{public id, 12 characters}_{secret, 40 characters}, letters and digits only.
 */
final class ApiKeys
{
    private const int PUBLIC_ID_LENGTH = 12;

    private const int SECRET_LENGTH = 40;

    /**
     * @return array{0: ApiKey, 1: string} The key and its plaintext, which is never stored.
     */
    public function create(string $name, ApiKeyLevel $level, ?DateTimeInterface $expiresAt = null, ?int $rateLimit = null, ?User $creator = null): array
    {
        do {
            $publicId = Str::random(self::PUBLIC_ID_LENGTH);
        } while (ApiKey::query()->withoutGlobalScope(CompanyScope::class)->where('public_id', $publicId)->exists());

        $plaintext = config('developers.api_keys.prefix').$publicId.'_'.Str::random(self::SECRET_LENGTH);

        $key = ApiKey::create([
            'name' => $name,
            'level' => $level,
            'public_id' => $publicId,
            'secret_hash' => self::hash($plaintext),
            'rate_limit_per_minute' => $rateLimit,
            'expires_at' => $expiresAt,
            'created_by' => $creator?->getKey(),
        ]);

        return [$key, $plaintext];
    }

    /**
     * The key a plaintext names, of any company, whatever its status; null when it is not a key of
     * this platform. The comparison runs in constant time, even when no key has that public id.
     */
    public function find(string $plaintext): ?ApiKey
    {
        $prefix = preg_quote((string) config('developers.api_keys.prefix'), '/');

        if (preg_match('/^'.$prefix.'([A-Za-z0-9]{'.self::PUBLIC_ID_LENGTH.'})_[A-Za-z0-9]{'.self::SECRET_LENGTH.'}$/', $plaintext, $match) !== 1) {
            return null;
        }

        $key = ApiKey::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->with('company')
            ->where('public_id', $match[1])
            ->first();

        $matches = hash_equals($key?->secret_hash ?? str_repeat('0', 64), self::hash($plaintext));

        return $key !== null && $matches ? $key : null;
    }

    public function revoke(ApiKey $key, ?User $actor = null): ApiKey
    {
        if ($key->revoked_at === null) {
            $key->forceFill(['revoked_at' => Carbon::now(), 'revoked_by' => $actor?->getKey()])->save();
        }

        return $key;
    }

    private static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
