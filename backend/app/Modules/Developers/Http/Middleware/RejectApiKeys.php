<?php

namespace App\Modules\Developers\Http\Middleware;

use App\Modules\Identity\Tenancy\Actor;
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
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Actor::apiKey($request) !== null) {
            throw new AccessDeniedHttpException(__('developers::errors.session_only'));
        }

        return $next($request);
    }
}
