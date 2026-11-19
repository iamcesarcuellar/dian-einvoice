<?php

namespace App\Modules\Identity\Tenancy;

use App\Modules\Developers\Auth\ApiKeyAuthenticator;
use App\Modules\Developers\Models\ApiKey;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;

/**
 * Who acts on a request, for the services that record it: the signed-in user, or null when the
 * request signs with an API key (the timelines then name the key, see CurrentCompany::apiKey()).
 */
final class Actor
{
    public static function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Who to count a per-user rate limit against: the API key, the signed-in user, or the address
     * (IP) when there is neither.
     */
    public static function limitKey(Request $request): string
    {
        return match (true) {
            self::apiKey($request) !== null => 'key:'.self::apiKey($request)->id,
            self::user($request) !== null => 'user:'.self::user($request)->id,
            default => 'ip:'.$request->ip(),
        };
    }

    /**
     * The API key the request signs with, or null for a session. Asked of the api-key guard itself:
     * after "auth" it holds the key of a request with a bearer token, and finds none without one.
     */
    public static function apiKey(Request $request): ?ApiKey
    {
        $key = $request->user(ApiKeyAuthenticator::GUARD);

        return $key instanceof ApiKey ? $key : null;
    }
}
