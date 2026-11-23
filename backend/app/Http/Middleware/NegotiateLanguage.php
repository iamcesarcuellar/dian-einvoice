<?php

namespace App\Http\Middleware;

use App\Support\Language;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of every response, errors included: English by default, Spanish when Accept-Language prefers it
 * (es, es-CO, es-419). Validation messages with their field names, error details and the fixed texts of problems
 * come in that language, and so do the emails the request sends (an invitation, a password reset link). The
 * response names its language in Content-Language and says it varies with Accept-Language. The language of the
 * application goes back to what it was once the response is ready, errors rendered included.
 */
final class NegotiateLanguage
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $language = Language::fromRequest($request);

        $response = $language->run(fn (): Response => $next($request));
        $response->headers->set('Content-Language', $language->value);
        $response->setVary('Accept-Language', false);

        return $response;
    }
}
