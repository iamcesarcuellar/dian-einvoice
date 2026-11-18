<?php

namespace App\Modules\Payments\Enums;

/** Entries of the timeline of a payment. */
enum PaymentEventType: string
{
    /** Payload: number, amount and the invoices with what each one took and its balance after. */
    case Recorded = 'recorded';

    /** Payload: reason and the invoices with what each one got back and its balance after. */
    case Voided = 'voided';
}
