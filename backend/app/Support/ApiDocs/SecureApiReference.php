<?php

namespace App\Support\ApiDocs;

use App\Http\SecurityHeaderPolicy;
use Closure;
use Dedoc\Scramble\Support\DevTools;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page of the API reference (Scramble at /docs/api) behind a Content-Security-Policy of its own, from
 * resources/http/security-headers.json: behind the edge it shares the origin of the session of the web app. The
 * page loads Stoplight Elements from unpkg.com: each of its two files gets the Subresource Integrity the file pins,
 * so the browser runs those exact bytes or nothing, and the policy names those exact files. The inline scripts of
 * the page (the interceptor of Scramble that sends the CSRF token, and the document of the API itself, which
 * changes with the API) are allowed by the hashes of this answer, never by 'unsafe-inline'. The development tools
 * of Scramble, enabled only with APP_DEBUG outside the functions (SCRAMBLE_DEV_TOOLS), are allowed by their URL.
 * Route middleware of the documentation routes (config/scramble.php); other answers pass through.
 */
final class SecureApiReference
{
    private const string DOCUMENT = 'apiReference';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->routeIs('scramble.docs.ui') || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();

        foreach (SecurityHeaderPolicy::subresourceIntegrity(self::DOCUMENT) as $url => $integrity) {
            $html = str_replace(['src="'.$url.'"', 'href="'.$url.'"'], ['src="'.$url.'" integrity="'.$integrity.'" crossorigin="anonymous"', 'href="'.$url.'" integrity="'.$integrity.'" crossorigin="anonymous"'], $html, $pinned);

            if ($pinned !== 1) {
                throw new LogicException("The page of the API reference loads {$url} {$pinned} times, not once: update resources/http/security-headers.json with the files of its renderer.");
            }
        }

        $scripts = self::inlineScriptHashes($html);

        if (DevTools::enabled()) {
            $scripts[] = route('scramble.dev-tools.asset');
        }

        $response->setContent($html);
        $response->headers->set('Content-Security-Policy', SecurityHeaderPolicy::contentSecurityPolicy(self::DOCUMENT, ['script-src' => $scripts]));

        foreach (SecurityHeaderPolicy::headers(self::DOCUMENT) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * The hash sources of the inline scripts of a page that a browser would run, in order: the SHA-256 of the text
     * between the tags, as the browser computes it. Scripts with a src and data blocks (a type that is not
     * JavaScript, such as application/json) are left out.
     *
     * @return list<string>
     */
    public static function inlineScriptHashes(string $html): array
    {
        preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $scripts, PREG_SET_ORDER);
        $hashes = [];

        foreach ($scripts as [, $attributes, $code]) {
            $type = preg_match('/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attributes, $declared) === 1 ? strtolower($declared[1]) : '';

            if (preg_match('/\bsrc\s*=/i', $attributes) === 1 || ! in_array($type, ['', 'module', 'text/javascript', 'application/javascript'], true)) {
                continue;
            }

            $hashes[] = "'sha256-".base64_encode(hash('sha256', $code, true))."'";
        }

        return array_values(array_unique($hashes));
    }
}
