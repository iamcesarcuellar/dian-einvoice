<?php

namespace App\Support\ApiDocs;

use App\Http\SecurityHeaderPolicy;
use Closure;
use Dedoc\Scramble\Support\DevTools;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pages of the API reference (Scramble at /docs/api, and in Spanish at /docs/api/es) behind a
 * Content-Security-Policy of their own, from resources/http/security-headers.json: behind the edge they share the
 * origin of the session of the web app. A page loads Stoplight Elements from unpkg.com: each of its two files gets the
 * Subresource Integrity the file pins, so the browser runs those exact bytes or nothing, and the policy names those
 * exact files. The inline scripts of the page (the interceptor of Scramble that sends the CSRF token, and the document
 * of the API itself, which changes with the API) are allowed by the hashes of this answer, never by 'unsafe-inline'.
 * The development tools of Scramble, enabled only with APP_DEBUG outside the functions (SCRAMBLE_DEV_TOOLS), are
 * allowed by their URL. The Spanish page declares its language and takes its title from ReferenceCatalog. Route
 * middleware of the documentation routes (config/scramble.php); other answers pass through.
 */
final class SecureApiReference
{
    private const string DOCUMENT = 'apiReference';

    /** The pages of the reference, by the name of their route, with the language each one is in. */
    private const array PAGES = ['scramble.docs.ui' => 'en', 'scramble.docs.es.ui' => 'es'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();
        $page = $route instanceof Route ? $route->getName() : null;

        if (! is_string($page) || ! array_key_exists($page, self::PAGES) || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return $response;
        }

        $html = (string) $response->getContent();

        if (self::PAGES[$page] === 'es') {
            $html = self::inSpanish($html);
        }

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

    /** The Spanish page: lang="es", and the title of the catalog. */
    private static function inSpanish(string $html): string
    {
        $html = preg_replace('/<html lang="en"/', '<html lang="es"', $html, 1, $languages);
        $html = preg_replace_callback('#<title>(.*?)</title>#s', fn (array $title): string => '<title>'.e(ReferenceCatalog::spanish()->pageTitle(html_entity_decode($title[1]))).'</title>', (string) $html, 1, $titles);

        if ($languages !== 1 || $titles !== 1 || ! is_string($html)) {
            throw new LogicException('The page of the API reference no longer starts with <html lang="en"> and one <title>: update App\Support\ApiDocs\SecureApiReference.');
        }

        return $html;
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
