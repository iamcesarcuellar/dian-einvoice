<?php

namespace App\Http\Controllers;

use App\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Health of the API, including its database connection.
 */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable $exception) {
            report($exception);

            return ProblemDetails::response(503, 'The database is not reachable.', ['checks' => ['database' => 'down']]);
        }

        return response()->json(['status' => 'ok', 'checks' => ['database' => 'ok']]);
    }
}
