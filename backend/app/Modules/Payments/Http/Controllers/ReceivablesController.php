<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Payments\Services\ReceivablesReport;
use Illuminate\Http\JsonResponse;

/**
 * GET /receivables: accounts receivable by customer, with the aging buckets (not due yet, 1 to 30,
 * 31 to 60, 61 to 90 and more than 90 days past due), the credit in favor of each customer, and the
 * totals of every column. Calculated by the server from the accepted invoices.
 */
final class ReceivablesController
{
    /**
     * List the receivables.
     *
     * Each customer's accounts receivable, by age: not due yet, 1 to 30, 31 to 60, 61 to 90 and more
     * than 90 days past due, with the credit in favor of each customer and the totals of every
     * column. The server calculates them from the accepted invoices.
     */
    public function __invoke(ReceivablesReport $report): JsonResponse
    {
        return response()->json(['data' => $report->build()]);
    }
}
