<?php

namespace App\Http\Controllers;

use App\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Health of the API, public: the database and Redis (queue, cache and sessions) answer. 200 with
 * the checks, or 503 as a problem naming the one that failed and nothing else. The healthcheck of
 * the api container calls it; the workers and the relay use php artisan health:check.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = ['database' => 'ok', 'redis' => 'ok'];

        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            report($exception);
            $checks['database'] = 'down';
        }

        try {
            Redis::connection()->ping();
        } catch (Throwable $exception) {
            report($exception);
            $checks['redis'] = 'down';
        }

        if (in_array('down', $checks, true)) {
            return ProblemDetails::response(503, 'A service the API needs is not reachable.', ['checks' => $checks]);
        }

        return response()->json(['status' => 'ok', 'checks' => $checks]);
    }
}
