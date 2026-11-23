<?php

namespace App\Modules\Developers\Http\Middleware;

use App\Http\ProblemDetails;
use App\Modules\Developers\RateLimits\WindowLimiter;
use App\Modules\Identity\Tenancy\Actor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests per minute of each API key (120 unless the key sets its own), counted in fixed windows of 60
 * seconds: in Redis locally, in the limits table of DynamoDB in the emulated cloud (WindowLimiter).
 * Every response to a key carries the limit headers: X-RateLimit-Limit, X-RateLimit-Remaining and
 * X-RateLimit-Reset (Unix time), plus RateLimit-Policy and RateLimit of the IETF draft. Above the
 * limit: 429 with Retry-After. Alias "api-rate-limit"; requests of the web app session are not limited
 * here.
 */
final class RateLimitApiKeys
{
    private const int WINDOW = 60;

    public function __construct(private readonly WindowLimiter $limiter) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = Actor::apiKey($request);

        if ($key === null) {
            return $next($request);
        }

        $limit = $key->rateLimit();
        $count = $this->limiter->hit('api-key-rate:'.$key->id, $limit, self::WINDOW);
        $allowed = $count->allowed;

        $reset = $count->resetsAt;
        $resetIn = max(1, $reset - time());
        $remaining = $count->remaining;

        $headers = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset' => (string) $reset,
            'RateLimit-Policy' => sprintf('"api-key";q=%d;w=%d', $limit, self::WINDOW),
            'RateLimit' => sprintf('"api-key";r=%d;t=%d', $remaining, $resetIn),
        ];

        if (! $allowed) {
            return ProblemDetails::response(
                429,
                __('developers::errors.rate_limited', ['limit' => $limit, 'seconds' => $resetIn]),
                headers: [...$headers, 'Retry-After' => (string) $resetIn],
            );
        }

        $response = $next($request);

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
