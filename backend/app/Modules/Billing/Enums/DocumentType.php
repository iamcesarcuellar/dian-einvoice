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

    public function isNote(): bool
    {
        return $this !== self::Invoice;
    }

    /** Path of its API resource, which also starts its route names: invoices, credit-notes or debit-notes. */
    public function path(): string
    {
        return match ($this) {
            self::Invoice => 'invoices',
            self::CreditNote => 'credit-notes',
            self::DebitNote => 'debit-notes',
        };
    }

    /** The type a route of the API acts on, from its name, such as credit-notes.show. */
    public static function forRoute(?string $name): self
    {
        foreach (self::cases() as $type) {
            if (str_starts_with((string) $name, $type->path().'.')) {
                return $type;
            }
        }

        return self::Invoice;
    }

    /** Code of table 13.1.3 of annex 1.9: 01 electronic sales invoice, 91 credit note, 92 debit note. */
    public function dianCode(): string
    {
        return match ($this) {
            self::Invoice => '01',
            self::CreditNote => '91',
            self::DebitNote => '92',
        };
    }

    /** Prefix of its XML file names (annex 1.9, 6.5.7): fv, nc or nd. */
    public function filePrefix(): string
    {
        return match ($this) {
            self::Invoice => 'fv',
            self::CreditNote => 'nc',
            self::DebitNote => 'nd',
        };
    }

    /** In English, for messages: invoice, credit note or debit note. */
    public function noun(): string
    {
        return match ($this) {
            self::Invoice => 'invoice',
            self::CreditNote => 'credit note',
            self::DebitNote => 'debit note',
        };
    }
}
