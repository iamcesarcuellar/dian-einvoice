<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the edge (config edge.behind, the functions), the address of the client is the one the edge saw, the entry
 * CloudFront appended to X-Forwarded-For, found without counting hops: CloudFront appends the address of the connection
 * of the client to whatever X-Forwarded-For the client sent, and API Gateway may append after it the address of its own
 * peer, the edge location, which it also reports as the source address of the request (requestContext.http.sourceIp,
 * which Bref passes on in LAMBDA_REQUEST_CONTEXT). So the last entry is dropped only when it is that peer, compared as
 * normalized addresses, and the last entry left is the one the edge appended, whether API Gateway appended its own or
 * not. Everything before it is the client's to write and counts for nothing, CloudFront-Viewer-Address and any other
 * header included. The request then carries that address as its only forwarded address, so $request->ip(), which the
 * rate limits count by, is the client's: the functions trust the Bref runtime alone (TRUSTED_PROXIES 127.0.0.1), which
 * takes the last forwarded address. Without a peer, without that entry, or when it is not an address, no forwarded
 * address is believed and the address is that of the runtime, shared by every such request: a missing entry can make
 * a limit stricter, never looser. Without the edge (the local stack) nothing changes.
 */
final class EdgeClientAddress
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config()->boolean('edge.behind')) {
            $address = self::addressTheEdgeSaw((string) $request->headers->get('X-Forwarded-For', ''), self::peer($request));

            if ($address === null) {
                $request->headers->remove('X-Forwarded-For');
            } else {
                $request->headers->set('X-Forwarded-For', $address);
            }
        }

        return $next($request);
    }

    /**
     * The entry of X-Forwarded-For the edge appended, normalized: the last one, once the last is dropped when it is the
     * peer API Gateway reports (it appended the address of its own connection). Null without a peer, when no entry is
     * left, or when that entry is not an address.
     */
    public static function addressTheEdgeSaw(string $forwardedFor, ?string $peer): ?string
    {
        $peer = $peer === null ? null : self::normalized($peer);

        if ($peer === null) {
            return null;
        }

        $entries = array_values(array_filter(array_map(trim(...), explode(',', $forwardedFor)), fn (string $entry): bool => $entry !== ''));

        if ($entries !== [] && self::normalized($entries[count($entries) - 1]) === $peer) {
            array_pop($entries);
        }

        return $entries === [] ? null : self::normalized($entries[count($entries) - 1]);
    }

    /**
     * An address in one spelling for each: IPv6 compressed and in lower case, an IPv4 address mapped into IPv6 as the
     * IPv4 address. Null when it is not an address (a host name, a port, a zone, brackets).
     */
    public static function normalized(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($address);
        $text = $packed === false ? false : inet_ntop($packed);

        if ($text === false) {
            return null;
        }

        return str_starts_with($text, '::ffff:') && filter_var(substr($text, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? substr($text, 7) : $text;
    }

    /**
     * The source address API Gateway reports for the connection it received (payload 2.0 of the HTTP API, or the REST
     * API's identity), from the request context of the event, which Bref's PHP-FPM runtime passes as a server variable
     * no header can set (headers arrive as HTTP_*). Null outside Lambda.
     */
    private static function peer(Request $request): ?string
    {
        $context = $request->server('LAMBDA_REQUEST_CONTEXT');
        $decoded = is_string($context) ? json_decode($context, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        $http = is_array($decoded['http'] ?? null) ? $decoded['http'] : [];
        $identity = is_array($decoded['identity'] ?? null) ? $decoded['identity'] : [];
        $source = $http['sourceIp'] ?? $identity['sourceIp'] ?? null;

        return is_string($source) ? $source : null;
    }
}
