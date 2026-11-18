<?php

namespace App\Modules\Billing\Enums;

enum DocumentType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';

    /**
     * Invoices need a DIAN resolution, validity dates and a technical key; notes do not.
     */
    public function requiresResolution(): bool
    {
        return $this === self::Invoice;
    }
}
