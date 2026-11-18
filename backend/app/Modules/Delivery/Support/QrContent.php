<?php

namespace App\Modules\Delivery\Support;

use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentTax;
use Brick\Math\BigDecimal;

/**
 * The text of the QR code of the graphic representation, exactly as section 11.7 of annex 1.9 and
 * its example write it: one "Field: value" line each for NumFac, FecFac, HorFac (with its offset),
 * NitFac and DocAdq (no dots or dashes), ValFac, ValIva, ValOtroIm and ValTolFac (point for decimals,
 * two decimals, no thousands separators, no currency sign), CUFE (CUDE for a note), and the verification URL of 11.7.1
 * (sts:QRCode of the XML) on the last line. Read from the frozen copies of the document.
 */
final class QrContent
{
    /** DIAN code of IVA (table 13.2.2); every other tax goes into ValOtroIm. */
    private const string IVA = '01';

    public static function for(Document $document): string
    {
        $iva = BigDecimal::zero();
        $other = BigDecimal::zero();

        foreach ($document->taxes as $tax) {
            /** @var DocumentTax $tax */
            if ($tax->dian_code === self::IVA) {
                $iva = $iva->plus($tax->tax_amount);
            } else {
                $other = $other->plus($tax->tax_amount);
            }
        }

        return implode("\n", [
            'NumFac: '.$document->full_number,
            'FecFac: '.$document->issue_date?->toDateString(),
            'HorFac: '.$document->issue_time,
            'NitFac: '.self::digits((string) $document->company_snapshot['identification_number']),
            'DocAdq: '.self::digits((string) $document->customer_snapshot['identification_number']),
            'ValFac: '.self::amount((string) $document->line_extension_amount),
            'ValIva: '.self::amount((string) $iva),
            'ValOtroIm: '.self::amount((string) $other),
            'ValTolFac: '.self::amount((string) $document->payable_amount),
            // A note carries its CUDE with the same labels (an assumption: 11.7 only shows the invoice).
            $document->keyName().': '.$document->cufe,
            (string) $document->qr_url,
        ]);
    }

    private static function amount(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2);
    }

    /** Without dots, dashes or spaces. */
    private static function digits(string $value): string
    {
        return str_replace(['.', '-', ' '], '', $value);
    }
}
