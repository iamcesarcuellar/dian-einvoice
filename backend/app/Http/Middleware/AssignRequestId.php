<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an id, answered in the X-Request-Id header and written as request_id into
 * every log line of the request and of the jobs it queues (Laravel's Context travels with them).
 * An X-Request-Id sent by a proxy in front of the API is kept when it is 8 to 128 letters, digits,
 * dots, colons, underscores or dashes; anything else is replaced by a new ULID. The first global
 * middleware, so every answer carries it, errors included.
 */
final class AssignRequestId
{
    public const string HEADER = 'X-Request-Id';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sent = $request->headers->get(self::HEADER);
        $id = is_string($sent) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $sent) === 1 ? $sent : Str::lower((string) Str::ulid());

        $request->headers->set(self::HEADER, $id);
        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** The id of the current request, or null outside a request (a job keeps the one of its request). */
    public static function current(): ?string
    {
        $id = Context::get('request_id');

        return is_string($id) ? $id : null;
    }
}
