<?php

namespace App\Http\Middleware;

use App\Http\SecurityHeaderPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers of every answer of the API, from their single source of truth
 * (resources/http/security-headers.json): the browser must not guess content types, send a referrer or frame any
 * answer. JSON answers (data and problems) also get a Content-Security-Policy that allows nothing, since none is
 * meant to render as a page; files (PDF, XML, zip) keep their own content, and the page of the API reference has a
 * policy of its own (App\Support\ApiDocs\SecureApiReference). In production, Strict-Transport-Security tells
 * browsers to use HTTPS only. The web app is static and gets its headers from the edge, from the same file.
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', SecurityHeaderPolicy::common('X-Content-Type-Options'));
        $headers->set('X-Frame-Options', SecurityHeaderPolicy::common('X-Frame-Options'));

        $type = (string) $headers->get('Content-Type', '');
        $document = $type === '' || str_contains($type, 'json') ? 'api' : 'files';

        foreach (SecurityHeaderPolicy::headers($document) as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', SecurityHeaderPolicy::contentSecurityPolicy($document));
        }

        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', SecurityHeaderPolicy::common('Strict-Transport-Security'));
        }

        return $response;
    }
}
