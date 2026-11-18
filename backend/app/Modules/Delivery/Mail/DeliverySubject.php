<?php

namespace App\Modules\Delivery\Mail;

use App\Modules\Billing\Models\Document;

/**
 * Subject of the email that delivers an electronic invoice (annex 1.9, section 9.1): NIT of the
 * issuer; its name; number of the document (cbc:ID); code of the document type (table 13.1.3, 01
 * for an electronic sales invoice); trade name of the issuer, or its legal name when it has none.
 * Separated by ";". The optional sixth field, the line of business, is agreed between the parties
 * and left out.
 */
final class DeliverySubject
{
    /** InvoiceTypeCode of an electronic sales invoice (table 13.1.3). */
    public const string INVOICE_TYPE = '01';

    public static function for(Document $document): string
    {
        $company = $document->company_snapshot;
        $legalName = self::clean((string) $company['legal_name']);
        $tradeName = self::clean((string) ($company['name'] ?? ''));

        return implode(';', [
            (string) $company['identification_number'],
            $legalName,
            (string) $document->full_number,
            self::INVOICE_TYPE,
            $tradeName !== '' ? $tradeName : $legalName,
        ]);
    }

    /** A field without the separator or line breaks, which would change the meaning of the subject. */
    private static function clean(string $value): string
    {
        return trim(preg_replace('/[;\r\n]+/', ' ', $value) ?? $value);
    }
}
