<?php

namespace App\Modules\Workspace\Http\Controllers;

use App\Modules\Workspace\Services\DashboardReport;
use Illuminate\Http\JsonResponse;

/**
 * GET /dashboard: everything the dashboard shows, in one answer, so the screen makes a single
 * request. Indicators of the month to date against the same days of the previous month, the
 * invoiced and collected series of the last six months, the receivables by age, the electronic
 * documents of the last 30 days and those that need attention, the notices on the invoice numbering
 * and the signing certificate, the top customers of the last 90 days and the recent activity.
 */
final class DashboardController
{
    public function __invoke(DashboardReport $report): JsonResponse
    {
        return response()->json(['data' => $report->build()]);
    }
}
