<?php

namespace App\Modules\Developers\Auth;

use App\Modules\Developers\Models\ApiKey;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * The api-key guard: Authorization: Bearer with an API key. A request without a bearer token is not
 * its business (the session guard may take it); a bearer token that is not an active key of this
 * platform answers 401 at once. The key never appears in an error, a log or a response.
 */
final class ApiKeyAuthenticator
{
    public const string GUARD = 'api-key';

    public function __construct(private readonly ApiKeys $keys) {}

    public function __invoke(Request $request): ?ApiKey
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $key = $this->keys->find($token);

        if ($key === null) {
            throw new AuthenticationException('The API key is not valid. Send an API key of this platform in the Authorization header: Bearer dei_...', [self::GUARD]);
        }

        if ($key->status() === 'revoked') {
            throw new AuthenticationException(sprintf('The API key %s was revoked.', $key->visibleId()), [self::GUARD]);
        }

        if ($key->status() === 'expired') {
            throw new AuthenticationException(sprintf('The API key %s expired on %s.', $key->visibleId(), $key->expires_at?->toIso8601String()), [self::GUARD]);
        }

        $key->touchLastUsed();

        return $key;
    }
}
