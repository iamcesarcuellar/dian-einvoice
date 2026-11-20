<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Behind the edge (config edge.behind, the functions), the address of the client is the one the edge saw, taken from
 * X-Forwarded-For by the known hops of the stage alone (config edge.forwarded_hops): CloudFront appends the address
 * of the connection of the client to whatever X-Forwarded-For the client sent, and API Gateway, in AWS, the address
 * of the edge location after it. Counting those entries from the end gives the one the edge appended; everything
 * before it is the client's to write and counts for nothing, CloudFront-Viewer-Address and any other header
 * included. The request then carries that address as its only forwarded address, so $request->ip(), which the rate
 * limits count by, is the client's: the functions trust the Bref runtime alone (TRUSTED_PROXIES 127.0.0.1), which
 * takes the last forwarded address. Without that entry, or when it is not an address, no forwarded address is
 * believed and the address is that of the runtime, shared by every such request: a missing hop can make a limit
 * stricter, never looser. Without the edge (the local stack) nothing changes.
 */
final class EdgeClientAddress
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config()->boolean('edge.behind')) {
            $address = self::addressTheEdgeSaw((string) $request->headers->get('X-Forwarded-For', ''), config()->integer('edge.forwarded_hops'));

            if ($address === null) {
                $request->headers->remove('X-Forwarded-For');
            } else {
                $request->headers->set('X-Forwarded-For', $address);
            }
        }

        return $next($request);
    }

    /**
     * The entry of X-Forwarded-For the edge appended: the $hops-th from the end, the known hops of the stage having
     * appended the last $hops entries. Null when the header holds fewer entries than that, or that one is not an
     * address.
     */
    public static function addressTheEdgeSaw(string $forwardedFor, int $hops): ?string
    {
        $entries = array_values(array_filter(array_map(trim(...), explode(',', $forwardedFor)), fn (string $entry): bool => $entry !== ''));

        if ($hops < 1 || count($entries) < $hops) {
            return null;
        }

        $entry = $entries[count($entries) - $hops];

        return filter_var($entry, FILTER_VALIDATE_IP) === false ? null : $entry;
    }
}
