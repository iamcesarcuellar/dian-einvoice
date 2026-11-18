<?php

namespace App\Modules\Developers\Http\Controllers;

use App\Http\Listing;
use App\Modules\Developers\Auth\ApiKeys;
use App\Modules\Developers\Enums\ApiKeyLevel;
use App\Modules\Developers\Http\Requests\ApiKeyRequest;
use App\Modules\Developers\Http\Resources\ApiKeyResource;
use App\Modules\Developers\Models\ApiKey;
use App\Modules\Identity\Tenancy\Actor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API keys of the current company, for owners and admins in the web app. The key is shown once,
 * in the answer that creates it; only its SHA-256 is stored. Keys are revoked, never deleted.
 */
final class ApiKeyController
{
    /** Newest first, revoked and expired keys included. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ApiKey::query()->with('creator')->orderByDesc('created_at');

        return ApiKeyResource::collection(Listing::paginate($query, $request, ['name', 'public_id']));
    }

    /**
     * Create a key. The answer carries the key itself in key, once: it cannot be shown again.
     */
    public function store(ApiKeyRequest $request, ApiKeys $keys): JsonResponse
    {
        [$key, $plaintext] = $keys->create(
            (string) $request->validated('name'),
            ApiKeyLevel::from((string) $request->validated('level')),
            $request->expiresAt(),
            $request->validated('rate_limit_per_minute') === null ? null : (int) $request->validated('rate_limit_per_minute'),
            Actor::user($request),
        );

        $data = ApiKeyResource::make($key->load('creator'))->resolve($request);

        return response()->json(['data' => [...$data, 'key' => $plaintext]], 201);
    }

    /** Revoke a key: it stops working at once. Revoking it again changes nothing. */
    public function revoke(Request $request, ApiKey $apiKey, ApiKeys $keys): ApiKeyResource
    {
        return ApiKeyResource::make($keys->revoke($apiKey, Actor::user($request))->load('creator'));
    }
}
