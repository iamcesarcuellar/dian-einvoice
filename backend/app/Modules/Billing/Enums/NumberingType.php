<?php

namespace App\Modules\Billing\Enums;

/**
 * What a numbering range numbers: the three documents, and the receipts of the payments the
 * company records. Only invoices need a DIAN resolution, its date and a technical key.
 */
enum NumberingType: string
{
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote = 'debit_note';
    case Payment = 'payment';

    public function requiresResolution(): bool
    {
        return $this === self::Invoice;
    }

    /** The numbering type of a document type. */
    public static function forDocument(DocumentType $type): self
    {
        return self::from($type->value);
    }

    /** In English, for messages: invoices, credit notes, debit notes or payment receipts. */
    public function plural(): string
    {
        return match ($this) {
            self::Invoice => 'invoices',
            self::CreditNote => 'credit notes',
            self::DebitNote => 'debit notes',
            self::Payment => 'payment receipts',
        };
    }
}
