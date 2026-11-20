<?php

namespace App\Http\Controllers;

use App\Http\ProblemDetails;
use App\Support\Health\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * Health of the API, public: the services it needs answer (HealthChecks: the database and Redis locally;
 * the database, the cache store and the queue in the emulated cloud). 200 with the checks, or 503 as a
 * problem naming the one that failed and nothing else. The healthcheck of the api container calls it;
 * the workers and the relay use php artisan health:check.
 */
final class HealthController
{
    public function __invoke(HealthChecks $health): JsonResponse
    {
        $checks = array_map(fn (bool $passed): string => $passed ? 'ok' : 'down', $health->run());

        if (in_array('down', $checks, true)) {
            return ProblemDetails::response(503, 'A service the API needs is not reachable.', ['checks' => $checks]);
        }

        return response()->json(['status' => 'ok', 'checks' => $checks]);
    }
}
