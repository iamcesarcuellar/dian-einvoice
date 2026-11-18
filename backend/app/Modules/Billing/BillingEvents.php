<?php

namespace App\Modules\Billing;

/** Types of the outbox events Billing publishes about the balance of its invoices. */
final class BillingEvents
{
    /** An accepted credit note with the void concept voided an invoice. */
    public const string INVOICE_VOIDED = 'invoice.voided';
}
