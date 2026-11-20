<?php

namespace App\Http\Middleware;

use App\Support\Observability\Correlation;
use App\Support\Observability\LogEvent;
use App\Support\Observability\LogSchema;
use App\Support\Observability\Telemetry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request to the API is the first entry of a journey: it gets its correlation id (the X-Correlation-Id the client
 * sent when it has the form of one, a new one otherwise), which every log line of the request, every outbox event
 * it records and every job it dispatches carry, and which the answer returns in X-Correlation-Id, errors included.
 * In the functions (config observability.requests) the request also writes one line when it is answered
 * (http.request): its route as declared, method, status and time, and who asked, never its body, query or headers.
 */
final class TraceRequest
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = LogSchema::correlationHeader();
        $sent = $request->headers->get($header);
        $id = Correlation::begin(is_string($sent) ? $sent : null);
        $request->headers->set($header, $id);
        $started = hrtime(true);

        $response = $next($request);
        $response->headers->set($header, $id);

        if (config()->boolean('observability.requests')) {
            $status = $response->getStatusCode();
            $route = $request->route();
            $uri = $route instanceof Route ? $route->uri() : null;

            Telemetry::record(
                LogEvent::HttpRequest,
                sprintf('%s %s answered %d.', $request->getMethod(), $uri ?? 'an unknown route', $status),
                outcome: match (true) {
                    $status >= 500 => 'failure',
                    $status >= 400 => 'rejected',
                    default => 'success',
                },
                durationMs: Telemetry::since($started),
                detail: [
                    'method' => $request->getMethod(),
                    'route' => $uri,
                    'status' => $status,
                ],
                level: $status >= 500 ? 'error' : 'info',
            );
        }

        return $response;
    }
}
