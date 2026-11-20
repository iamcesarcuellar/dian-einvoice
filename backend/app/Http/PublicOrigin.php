<?php

namespace App\Http;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;

/**
 * The public origin of the platform, for the URLs the application generates behind the edge (config/edge.php):
 * there it sees the host of API Gateway, which refuses whoever does not come through the edge, so a link built from
 * the host of the request (the pages of a list, the servers of the API reference) would lead nowhere. Every URL
 * then takes APP_URL, its scheme included, whatever host and scheme the request names.
 */
final class PublicOrigin
{
    public static function apply(string $origin): void
    {
        $origin = rtrim($origin, '/');

        URL::forceRootUrl($origin);
        URL::forceScheme((string) parse_url($origin, PHP_URL_SCHEME));
        Paginator::currentPathResolver(fn (): string => url(request()->path()));
    }
}
