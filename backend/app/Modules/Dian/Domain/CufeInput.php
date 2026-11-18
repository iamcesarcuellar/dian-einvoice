<?php

namespace App\Modules\Dian\Domain;

/**
 * The fifteen values of the CUFE of an invoice (annex 1.9, section 11.2), named as in the annex.
 * Amounts are decimal strings; the tax codes (01 IVA, 04 INC, 03 ICA) are fixed by the formula.
 */
final readonly class CufeInput
{
    public function __construct(
        /** NumFac: prefix and number together, such as SETP990000002. */
        public string $number,
        /** FecFac: YYYY-MM-DD. */
        public string $issueDate,
        /** HorFac: HH:MM:SS with the offset, such as 10:53:10-05:00. */
        public string $issueTime,
        /** ValFac: line extension amount, before taxes. */
        public string $lineExtensionAmount,
        /** ValImp1: total of tax 01 (IVA); 0 when the invoice has none. */
        public string $ivaAmount,
        /** ValImp2: total of tax 04 (INC). */
        public string $incAmount,
        /** ValImp3: total of tax 03 (ICA). */
        public string $icaAmount,
        /** ValTot: payable amount. */
        public string $payableAmount,
        /** NitOFE: NIT of the issuer, without the check digit. */
        public string $issuerNit,
        /** NumAdq: identification of the customer, without the check digit. */
        public string $customerId,
        /** ClTec: technical key of the numbering range. */
        public string $technicalKey,
        /** TipoAmbiente: 1 production, 2 test. */
        public int $environment,
    ) {}
}
