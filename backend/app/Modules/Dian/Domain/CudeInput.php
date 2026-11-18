<?php

namespace App\Modules\Dian\Domain;

/**
 * The fifteen values of the CUDE of a credit or debit note (annex 1.9, section 11.4), named as in
 * the annex. The same values as the CUFE, with the PIN of the software in the place of the
 * technical key. Amounts are decimal strings; the tax codes (01, 04, 03) are fixed by the formula.
 */
final readonly class CudeInput
{
    public function __construct(
        /** NumDoc: prefix and number of the note together, such as NC12. */
        public string $number,
        /** FecDoc: YYYY-MM-DD. */
        public string $issueDate,
        /** HorDoc: HH:MM:SS with the offset, such as 07:00:00-05:00. */
        public string $issueTime,
        /** ValDoc: line extension amount, before taxes. */
        public string $lineExtensionAmount,
        /** ValImp1: total of tax 01 (IVA); 0 when the note has none. */
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
        /** Software-PIN: the PIN of the software registered with the DIAN; it is not in the XML. */
        public string $softwarePin,
        /** TipoAmbiente: 1 production, 2 test. */
        public int $environment,
    ) {}
}
