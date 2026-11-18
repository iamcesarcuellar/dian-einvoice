<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\OpenInvoice;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;

/**
 * GET /contacts/{contact}/open-invoices: the accepted invoices of a customer with something left to
 * collect, oldest due first, with their due date, balance and whether they are overdue. Credits in
 * favor of the customer (negative balances) are listed apart.
 */
final class OpenInvoicesController
{
    public function __invoke(Contact $contact, InvoiceLedger $ledger): JsonResponse
    {
        $customer = $contact;
        $invoices = $ledger->openInvoices($customer->getKey());

        $open = array_values(array_filter($invoices, fn (OpenInvoice $invoice): bool => BigDecimal::of($invoice->balance)->isPositive()));
        $credits = array_values(array_filter($invoices, fn (OpenInvoice $invoice): bool => BigDecimal::of($invoice->balance)->isNegative()));
        $total = array_reduce($open, fn (BigDecimal $sum, OpenInvoice $invoice): BigDecimal => $sum->plus($invoice->balance), BigDecimal::zero());

        return response()->json([
            'data' => array_map(fn (OpenInvoice $invoice): array => $invoice->toArray(), $open),
            'meta' => [
                'contact_id' => $customer->getKey(),
                'total' => (string) $total->toScale(2),
                'credits' => array_map(fn (OpenInvoice $invoice): array => $invoice->toArray(), $credits),
            ],
        ]);
    }
}
