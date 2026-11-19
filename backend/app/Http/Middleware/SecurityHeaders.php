<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers of every answer of the API: the browser must not guess content types, send a
 * referrer or frame any answer. JSON answers (data and problems) also get a Content-Security-Policy
 * that allows nothing, since none is meant to render as a page; files (PDF, XML, zip) and the API
 * reference keep their own content. In production, Strict-Transport-Security tells browsers to
 * use HTTPS only. The web app is static and gets its headers from its host (Infrastructure).
 */
final class SecurityHeaders
{
    /** One year, as the HSTS preload list asks. */
    private const int HSTS_SECONDS = 31_536_000;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('X-Frame-Options', 'DENY');

        $type = (string) $headers->get('Content-Type', '');
        $json = $type === '' || str_contains($type, 'json');

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $json ? "default-src 'none'; frame-ancestors 'none'" : "frame-ancestors 'none'");
        }

        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', sprintf('max-age=%d; includeSubDomains', self::HSTS_SECONDS));
        }

        return $response;
    }
}
