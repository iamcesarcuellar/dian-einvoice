<?php

namespace App\Modules\Billing\Enums;

/**
 * Payment status of an issued invoice, from the payments applied to it: unpaid while nothing was
 * applied, partially_paid while something was and a balance is left, paid once nothing is left to
 * collect. Overdue is not a status: an invoice is overdue while it has a balance after its due date.
 */
enum InvoicePaymentStatus: string
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
}
