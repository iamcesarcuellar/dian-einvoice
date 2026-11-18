<?php

namespace App\Http;

/**
 * Middleware stacks of the API routes, so every module protects its routes the same way.
 */
final class ApiMiddleware
{
    /**
     * Tenant routes of the public API: the web app session (with X-Company-Id) or an API key (its
     * own company). Keys are rate limited, and any POST may carry an Idempotency-Key. Routes that
     * change data add permission:<name>, which a read-only key never has.
     */
    public const array TENANT = ['auth:api-key,sanctum', 'company', 'api-rate-limit', 'idempotency'];

    /**
     * Tenant routes of the web app session only (settings, certificates, the simulator, API keys,
     * webhooks): an API key gets 403.
     */
    public const array PANEL = ['auth:api-key,sanctum', 'panel', 'company', 'idempotency'];

    /** Routes without a company, such as the reference lists, for sessions and API keys. */
    public const array SIGNED_IN = ['auth:api-key,sanctum', 'api-rate-limit'];
}
