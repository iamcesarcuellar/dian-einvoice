<?php

namespace App\Modules\Developers\Http\Middleware;

use App\Modules\Developers\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Endpoints of the web app session only: users, API keys, webhooks, certificates, DIAN settings,
 * the simulator and the other development tools. An API key gets 403. Alias "panel".
 */
final class RejectApiKeys
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() instanceof ApiKey) {
            throw new AccessDeniedHttpException('API keys cannot use this endpoint: it belongs to the web app session of an owner or admin.');
        }

        return $next($request);
    }
}
