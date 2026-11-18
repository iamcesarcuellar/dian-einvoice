<?php

namespace App\Modules\Payments;

/** Types of the events the payments module publishes through the outbox. */
final class PaymentEvents
{
    public const string RECORDED = 'payment.recorded';

    public const string VOIDED = 'payment.voided';

    /** A payment left nothing to collect on an invoice. */
    public const string INVOICE_PAID = 'invoice.paid';
}
