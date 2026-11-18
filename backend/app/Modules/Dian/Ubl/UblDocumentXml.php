<?php

namespace App\Modules\Dian\Ubl;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentLine;
use App\Modules\Billing\Models\DocumentTax;
use App\Modules\Billing\Models\DocumentWithholding;
use App\Modules\Dian\Domain\Cude;
use App\Modules\Dian\Domain\Cufe;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\OrganizationType;
use Brick\Math\BigDecimal;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Builds the unsigned UBL 2.1 Invoice, CreditNote or DebitNote with the DIAN profile (annex 1.9,
 * sections 6.1, 6.2 and 6.3) from the frozen copies of an issued document: its company, customer,
 * numbering, software and referenced invoice snapshots, its line copies, tax subtotals,
 * withholdings and totals. Live company, customer, item or tax data is never read, and nothing is
 * recalculated: every amount is the one the document stores. Element order follows the official
 * XSD of each document; literals and codes follow the annex tables, and the official examples of
 * the DIAN toolbox where the tables say nothing.
 */
final class UblDocumentXml
{
    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const string NS_DEBIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2';

    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    public const string NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    public const string NS_STS = 'dian:gov:co:facturaelectronica:Structures-2-1';

    public const string NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    public const string NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    public const string NS_XADES141 = 'http://uri.etsi.org/01903/v1.4.1#';

    public const string NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const string SCHEMA_BASE = 'http://docs.oasis-open.org/ubl/os-UBL-2.1/xsd/maindoc/';

    /** Header literals (annex 1.9, FAD01 to FAD15, CAD01 to CAD15 and DAD01 to DAD15). */
    private const string UBL_VERSION = 'UBL 2.1';

    /**
     * CustomizationID: table 13.1.5.1, 10 standard invoice; table 13.1.5.2, 20 credit note that
     * references an invoice; table 13.1.5.3, 30 debit note that references an invoice.
     */
    private const array OPERATION = [
        'invoice' => '10',
        'credit_note' => '20',
        'debit_note' => '30',
    ];

    /** ProfileID (FAD03, CAD03 and DAD03). */
    private const array PROFILE = [
        'invoice' => 'DIAN 2.1: Factura Electrónica de Venta',
        'credit_note' => 'DIAN 2.1: Nota Crédito de Factura Electrónica de Venta',
        'debit_note' => 'DIAN 2.1: Nota Débito de Factura Electrónica de Venta',
    ];

    /** The DIAN as identification agency and as authorization provider (FAB20 to FAB35). */
    public const string DIAN_AGENCY_ID = '195';

    private const string DIAN_AGENCY_NAME = 'CO, DIAN (Dirección de Impuestos y Aduanas Nacionales)';

    private const string DIAN_NIT = '800197268';

    private const string DIAN_CHECK_DIGIT = '4';

    private const string UNECE_AGENCY_ID = '6';

    private const string UNECE_AGENCY_NAME = 'United Nations Economic Commission for Europe';

    private const string COUNTRY_LIST_URI = 'urn:oasis:names:specification:ubl:codelist:gc:CountryIdentificationCode-2.1';

    private const string COLOMBIA = 'CO';

    /** Names of the tax schemes of a party (table 13.2.6.2); the AttachedDocument uses them too. */
    public const array PARTY_TAX_SCHEMES = [
        '01' => 'IVA',
        '04' => 'INC',
        'ZA' => 'IVA e INC',
        'ZZ' => 'No aplica',
    ];

    /** Names of the taxes and withholdings (table 13.2.2). */
    private const array TRIBUTES = [
        '01' => 'IVA',
        '03' => 'ICA',
        '04' => 'INC',
        '05' => 'ReteIVA',
        '06' => 'ReteRenta',
        '07' => 'ReteICA',
    ];

    /** Product standards of table 13.3.5: scheme name => [@schemeID, @schemeAgencyID]. */
    private const array PRODUCT_STANDARDS = [
        'UNSPSC' => ['001', '10'],
        'GTIN' => ['010', '9'],
    ];

    /** Table 13.3.5: 999, the standard adopted by the taxpayer (its own codes). */
    private const string OWN_STANDARD = '999';

    /** Maximum lengths of the repeatable text elements (FAD13 and FAZ02). */
    private const int NOTE_LENGTH = 500;

    private const int DESCRIPTION_LENGTH = 300;

    private DOMDocument $dom;

    private string $currency;

    private DocumentType $type;

    /** Root element of each type; its XSD is UBL-{root}-2.1.xsd. */
    public static function root(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Invoice => 'Invoice',
            DocumentType::CreditNote => 'CreditNote',
            DocumentType::DebitNote => 'DebitNote',
        };
    }

    public static function namespace(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Invoice => self::NS_INVOICE,
            DocumentType::CreditNote => self::NS_CREDIT_NOTE,
            DocumentType::DebitNote => self::NS_DEBIT_NOTE,
        };
    }

    public function build(Document $document): string
    {
        if ($document->isDraft() || $document->cufe === null) {
            throw new LogicException("Only issued documents with a {$document->keyName()} have an XML.");
        }

        if ($document->isNote() && blank($document->related_snapshot['cufe'] ?? null)) {
            throw new LogicException('A note needs the frozen copy of the invoice it corrects.');
        }

        $document->loadMissing(['lines', 'taxes', 'withholdings']);

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->xmlStandalone = false;
        $this->dom->formatOutput = true;
        $this->currency = $document->currency;
        $this->type = $document->type;

        $name = self::root($this->type);
        $root = $this->dom->createElementNS(self::namespace($this->type), $name);
        $this->dom->appendChild($root);

        // The same declarations as the official examples; ds and xades are used by the signature.
        foreach (['cac' => self::NS_CAC, 'cbc' => self::NS_CBC, 'ds' => self::NS_DS, 'ext' => self::NS_EXT, 'sts' => self::NS_STS, 'xades' => self::NS_XADES, 'xades141' => self::NS_XADES141, 'xsi' => self::NS_XSI] as $prefix => $namespace) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:{$prefix}", $namespace);
        }

        $root->setAttributeNS(self::NS_XSI, 'xsi:schemaLocation', self::namespace($this->type).' '.self::SCHEMA_BASE."UBL-{$name}-2.1.xsd");

        $this->extensions($root, $document);
        $this->header($root, $document);

        if ($document->isNote()) {
            $this->correction($root, $document);
        }

        $this->supplier($root, $document);
        $this->customer($root, $document);
        $this->paymentMeans($root, $document);
        $this->taxTotals($root, 'TaxTotal', $this->taxGroups($document->taxes));

        // CreditNote and DebitNote have no WithholdingTaxTotal in their XSD: the withholdings of
        // a note stay in its PDF and in its amount due.
        if (! $document->isNote()) {
            $this->taxTotals($root, 'WithholdingTaxTotal', $this->withholdingGroups($document->withholdings));
        }

        $this->monetaryTotal($root, $document);

        foreach ($document->lines as $line) {
            $this->line($root, $line);
        }

        return $this->dom->saveXML();
    }

    /**
     * The DIAN extension (FAB01 to FAB36; CAB and DAB for the notes, which carry no InvoiceControl
     * because their numbering needs no resolution), then a second extension left empty: the XAdES
     * signature goes there.
     */
    private function extensions(DOMElement $root, Document $document): void
    {
        $dian = $document->dian_snapshot;
        $provider = $dian['software_provider'];

        $extensions = $this->ext($root, 'UBLExtensions');
        $content = $this->ext($this->ext($extensions, 'UBLExtension'), 'ExtensionContent');
        $dianExtensions = $this->sts($content, 'DianExtensions');

        if (! $document->isNote()) {
            $this->invoiceControl($dianExtensions, $document->numbering_snapshot);
        }

        $source = $this->sts($dianExtensions, 'InvoiceSource');
        $this->cbc($source, 'IdentificationCode', self::COLOMBIA, [
            'listAgencyID' => self::UNECE_AGENCY_ID,
            'listAgencyName' => self::UNECE_AGENCY_NAME,
            'listSchemeURI' => self::COUNTRY_LIST_URI,
        ]);

        $software = $this->sts($dianExtensions, 'SoftwareProvider');
        $this->sts($software, 'ProviderID', $provider['identification_number'], [
            ...$this->dianAgency(),
            'schemeID' => (string) $provider['check_digit'],
            'schemeName' => IdentificationType::Nit->value,
        ]);
        $this->sts($software, 'SoftwareID', $dian['software_id'], $this->dianAgency());

        $this->sts($dianExtensions, 'SoftwareSecurityCode', $document->software_security_code, $this->dianAgency());

        $authorization = $this->sts($dianExtensions, 'AuthorizationProvider');
        $this->sts($authorization, 'AuthorizationProviderID', self::DIAN_NIT, [
            ...$this->dianAgency(),
            'schemeID' => self::DIAN_CHECK_DIGIT,
            'schemeName' => IdentificationType::Nit->value,
        ]);

        $this->sts($dianExtensions, 'QRCode', $document->qr_url);

        // Reserved for the signature.
        $this->ext($this->ext($extensions, 'UBLExtension'), 'ExtensionContent');
    }

    /**
     * FAB02 to FAB13: the resolution of the numbering, as it was frozen on issue.
     *
     * @param  array<string, mixed>  $numbering
     */
    private function invoiceControl(DOMElement $dianExtensions, array $numbering): void
    {
        $control = $this->sts($dianExtensions, 'InvoiceControl');
        $this->sts($control, 'InvoiceAuthorization', $numbering['resolution_number']);
        $period = $this->sts($control, 'AuthorizationPeriod');
        $this->cbc($period, 'StartDate', $numbering['valid_from']);
        $this->cbc($period, 'EndDate', $numbering['valid_to']);
        $authorized = $this->sts($control, 'AuthorizedInvoices');

        if (filled($numbering['prefix'])) {
            $this->sts($authorized, 'Prefix', $numbering['prefix']);
        }

        $this->sts($authorized, 'From', (string) $numbering['from_number']);
        $this->sts($authorized, 'To', (string) $numbering['to_number']);
    }

    /**
     * FAD01 to FAD16, CAD01 to CAD16, DAD01 to DAD16. The order of the XSD: an invoice has DueDate
     * and InvoiceTypeCode; a credit note has CreditNoteTypeCode (91) and no DueDate; a debit note has
     * neither. Its UUID is the CUFE of an invoice or the CUDE of a note.
     */
    private function header(DOMElement $root, Document $document): void
    {
        $this->cbc($root, 'UBLVersionID', self::UBL_VERSION);
        $this->cbc($root, 'CustomizationID', self::OPERATION[$this->type->value]);
        $this->cbc($root, 'ProfileID', self::PROFILE[$this->type->value]);
        $this->cbc($root, 'ProfileExecutionID', (string) $document->dian_environment);
        $this->cbc($root, 'ID', $document->full_number);
        $this->cbc($root, 'UUID', $document->cufe, [
            'schemeID' => (string) $document->dian_environment,
            'schemeName' => $document->isNote() ? Cude::ALGORITHM : Cufe::ALGORITHM,
        ]);
        $this->cbc($root, 'IssueDate', $document->issue_date->toDateString());
        $this->cbc($root, 'IssueTime', $document->issue_time);

        if ($this->type === DocumentType::Invoice && $document->due_date !== null) {
            $this->cbc($root, 'DueDate', $document->due_date->toDateString());
        }

        // Table 13.1.3: 01 electronic sales invoice, 91 credit note; the DebitNote has no type code.
        match ($this->type) {
            DocumentType::Invoice => $this->cbc($root, 'InvoiceTypeCode', $this->type->dianCode()),
            DocumentType::CreditNote => $this->cbc($root, 'CreditNoteTypeCode', $this->type->dianCode()),
            DocumentType::DebitNote => null,
        };

        foreach ($this->chunks($document->notes, self::NOTE_LENGTH) as $note) {
            $this->cbc($root, 'Note', $note);
        }

        $this->cbc($root, 'DocumentCurrencyCode', $this->currency, [
            'listAgencyID' => self::UNECE_AGENCY_ID,
            'listAgencyName' => self::UNECE_AGENCY_NAME,
            'listID' => 'ISO 4217 Alpha',
        ]);
        $this->cbc($root, 'LineCountNumeric', (string) $document->lines->count());
    }

    /**
     * CBF01 to CBF04 and CBG01 to CBG06 (DBF and DBG for a debit note): the concept of the
     * correction with its description, and the invoice it corrects with its number, CUFE and date,
     * from the copy frozen on issue.
     */
    private function correction(DOMElement $root, Document $document): void
    {
        $invoice = $document->related_snapshot;

        $discrepancy = $this->cac($root, 'DiscrepancyResponse');
        $this->cbc($discrepancy, 'ReferenceID', $invoice['full_number']);
        $this->cbc($discrepancy, 'ResponseCode', (string) $document->reason_code);
        $this->cbc($discrepancy, 'Description', trim((string) $document->reason_text));

        $reference = $this->cac($this->cac($root, 'BillingReference'), 'InvoiceDocumentReference');
        $this->cbc($reference, 'ID', $invoice['full_number']);
        $this->cbc($reference, 'UUID', $invoice['cufe'], ['schemeName' => Cufe::ALGORITHM]);
        $this->cbc($reference, 'IssueDate', $invoice['issue_date']);
    }

    /** FAJ01 to FAJ72: the issuing company as it was frozen on issue. */
    private function supplier(DOMElement $root, Document $document): void
    {
        $company = $document->company_snapshot;
        $prefix = $document->numbering_snapshot['prefix'] ?? null;

        $supplierParty = $this->cac($root, 'AccountingSupplierParty');
        $this->cbc($supplierParty, 'AdditionalAccountID', $company['organization_type']);
        $party = $this->cac($supplierParty, 'Party');

        if (filled($company['name'])) {
            $this->cbc($this->cac($party, 'PartyName'), 'Name', $company['name']);
        }

        $this->address($this->cac($party, 'PhysicalLocation'), 'Address', $company);

        $taxScheme = $this->cac($party, 'PartyTaxScheme');
        $this->cbc($taxScheme, 'RegistrationName', $company['legal_name']);
        $this->companyId($taxScheme, $company);
        $this->cbc($taxScheme, 'TaxLevelCode', implode(';', $company['fiscal_responsibilities']));
        $this->address($taxScheme, 'RegistrationAddress', $company);
        $this->partyTaxScheme($taxScheme, $company['tax_scheme']);

        $legalEntity = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legalEntity, 'RegistrationName', $company['legal_name']);
        $this->companyId($legalEntity, $company);

        if (filled($prefix) || filled($company['registration_number'])) {
            $registration = $this->cac($legalEntity, 'CorporateRegistrationScheme');

            if (filled($prefix)) {
                $this->cbc($registration, 'ID', $prefix);
            }

            if (filled($company['registration_number'])) {
                $this->cbc($registration, 'Name', $company['registration_number']);
            }
        }

        $this->contact($party, $company);
    }

    /** FAK01 to FAK64: the customer as it was frozen on issue. */
    private function customer(DOMElement $root, Document $document): void
    {
        $customer = $document->customer_snapshot;

        $customerParty = $this->cac($root, 'AccountingCustomerParty');
        $this->cbc($customerParty, 'AdditionalAccountID', $customer['organization_type']);
        $party = $this->cac($customerParty, 'Party');

        // Natural persons, the final consumer among them, also carry their identification here (FAK61).
        if ($customer['organization_type'] === OrganizationType::NaturalPerson->value) {
            $this->cbc($this->cac($party, 'PartyIdentification'), 'ID', $customer['identification_number'], $this->identificationScheme($customer));
        }

        $this->cbc($this->cac($party, 'PartyName'), 'Name', filled($customer['trade_name']) ? $customer['trade_name'] : $customer['legal_name']);

        $hasAddress = $this->hasAddress($customer);

        if ($hasAddress) {
            $this->address($this->cac($party, 'PhysicalLocation'), 'Address', $customer);
        }

        $taxScheme = $this->cac($party, 'PartyTaxScheme');
        $this->cbc($taxScheme, 'RegistrationName', $customer['legal_name']);
        $this->companyId($taxScheme, $customer);

        if ($customer['fiscal_responsibilities'] !== []) {
            $this->cbc($taxScheme, 'TaxLevelCode', implode(';', $customer['fiscal_responsibilities']));
        }

        if ($hasAddress) {
            $this->address($taxScheme, 'RegistrationAddress', $customer);
        }

        $this->partyTaxScheme($taxScheme, $customer['tax_scheme']);

        $legalEntity = $this->cac($party, 'PartyLegalEntity');
        $this->cbc($legalEntity, 'RegistrationName', $customer['legal_name']);
        $this->companyId($legalEntity, $customer);

        $this->contact($party, $customer);
    }

    /** FAN01 to FAN04 (CAN, DAN): payment form (1 cash, 2 credit) and payment means. */
    private function paymentMeans(DOMElement $root, Document $document): void
    {
        $means = $this->cac($root, 'PaymentMeans');
        $this->cbc($means, 'ID', $document->payment_form->value);
        $this->cbc($means, 'PaymentMeansCode', $document->payment_means_code->value);

        if ($document->due_date !== null) {
            $this->cbc($means, 'PaymentDueDate', $document->due_date->toDateString());
        }
    }

    /**
     * One TaxTotal (FAS) or WithholdingTaxTotal (FAT) per DIAN code, with one subtotal per rate.
     * The total of a code is the sum of its stored subtotals.
     *
     * @param  array<string, list<array{taxable: string, amount: string, rate: string}>>  $groups
     */
    private function taxTotals(DOMElement $parent, string $element, array $groups): void
    {
        foreach ($groups as $code => $subtotals) {
            $total = $this->cac($parent, $element);
            $this->amount($total, 'TaxAmount', $this->sum(array_column($subtotals, 'amount')));

            foreach ($subtotals as $subtotal) {
                $this->taxSubtotal($total, (string) $code, $subtotal['taxable'], $subtotal['amount'], $subtotal['rate']);
            }
        }
    }

    /**
     * FAU01 to FAU15 (CAU; DAU in RequestedMonetaryTotal for a debit note). Withholdings do not
     * change these totals (annex 1.9, section 11.9.1).
     */
    private function monetaryTotal(DOMElement $root, Document $document): void
    {
        $total = $this->cac($root, $this->type === DocumentType::DebitNote ? 'RequestedMonetaryTotal' : 'LegalMonetaryTotal');
        $this->amount($total, 'LineExtensionAmount', $document->line_extension_amount);
        $this->amount($total, 'TaxExclusiveAmount', $document->tax_exclusive_amount);
        $this->amount($total, 'TaxInclusiveAmount', $document->tax_inclusive_amount);
        $this->amount($total, 'PayableAmount', $document->payable_amount);
    }

    /**
     * FAV, FBE, FAX, FAZ and FBB: one stored line copy, as InvoiceLine, CreditNoteLine or
     * DebitNoteLine. The XSD of an invoice line puts the discount before the tax; those of the
     * notes put the tax first, and DebitNoteLine has no FreeOfChargeIndicator.
     */
    private function line(DOMElement $root, DocumentLine $line): void
    {
        [$name, $quantity] = match ($this->type) {
            DocumentType::Invoice => ['InvoiceLine', 'InvoicedQuantity'],
            DocumentType::CreditNote => ['CreditNoteLine', 'CreditedQuantity'],
            DocumentType::DebitNote => ['DebitNoteLine', 'DebitedQuantity'],
        };

        $element = $this->cac($root, $name);
        $this->cbc($element, 'ID', (string) $line->position);
        $this->cbc($element, $quantity, $line->quantity, ['unitCode' => $line->unit_code]);
        $this->amount($element, 'LineExtensionAmount', $line->line_extension_amount);

        if ($this->type !== DocumentType::DebitNote) {
            $this->cbc($element, 'FreeOfChargeIndicator', 'false');
        }

        if ($this->type === DocumentType::Invoice) {
            $this->lineDiscount($element, $line);
            $this->lineTax($element, $line);
        } else {
            $this->lineTax($element, $line);
            $this->lineDiscount($element, $line);
        }

        $item = $this->cac($element, 'Item');

        foreach ($this->chunks($line->description, self::DESCRIPTION_LENGTH) as $description) {
            $this->cbc($item, 'Description', $description);
        }

        if (filled($line->code)) {
            $this->cbc($this->cac($item, 'SellersItemIdentification'), 'ID', $line->code);
        }

        $this->standardIdentification($item, $line);

        $price = $this->cac($element, 'Price');
        $this->amount($price, 'PriceAmount', $this->decimal($line->unit_price));
        $this->cbc($price, 'BaseQuantity', $line->quantity, ['unitCode' => $line->unit_code]);
    }

    private function lineDiscount(DOMElement $element, DocumentLine $line): void
    {
        if (! BigDecimal::of($line->discount_amount)->isPositive()) {
            return;
        }

        // The base is quantity times price: the stored line value plus its stored discount.
        $discount = $this->cac($element, 'AllowanceCharge');
        $this->cbc($discount, 'ID', '1');
        $this->cbc($discount, 'ChargeIndicator', 'false');
        $this->cbc($discount, 'MultiplierFactorNumeric', $this->decimal($line->discount_rate));
        $this->amount($discount, 'Amount', $line->discount_amount);
        $this->amount($discount, 'BaseAmount', (string) BigDecimal::of($line->line_extension_amount)->plus($line->discount_amount));
    }

    /** Excluded lines carry no tax group at all (FAX01). */
    private function lineTax(DOMElement $element, DocumentLine $line): void
    {
        if ($line->tax_code === null) {
            return;
        }

        $tax = $this->cac($element, 'TaxTotal');
        $this->amount($tax, 'TaxAmount', $line->tax_amount);
        $this->taxSubtotal($tax, $line->tax_code, $line->line_extension_amount, $line->tax_amount, $line->tax_rate);
    }

    /**
     * FAZ09 to FAZ12: UNSPSC or GTIN when the item had one; otherwise the company's own code
     * (999), which is the line code, or the line number for free lines without a code.
     */
    private function standardIdentification(DOMElement $item, DocumentLine $line): void
    {
        $scheme = strtoupper(trim((string) $line->standard_scheme));
        $standard = self::PRODUCT_STANDARDS[$scheme] ?? null;
        $identification = $this->cac($item, 'StandardItemIdentification');

        if ($standard !== null && filled($line->standard_code)) {
            [$schemeId, $agencyId] = $standard;
            $this->cbc($identification, 'ID', $line->standard_code, ['schemeAgencyID' => $agencyId, 'schemeID' => $schemeId, 'schemeName' => $scheme]);

            return;
        }

        $this->cbc($identification, 'ID', filled($line->code) ? $line->code : (string) $line->position, ['schemeID' => self::OWN_STANDARD]);
    }

    private function taxSubtotal(DOMElement $total, string $code, string $taxable, string $amount, string $rate): void
    {
        $subtotal = $this->cac($total, 'TaxSubtotal');
        $this->amount($subtotal, 'TaxableAmount', $taxable);
        $this->amount($subtotal, 'TaxAmount', $amount);
        $category = $this->cac($subtotal, 'TaxCategory');
        $this->cbc($category, 'Percent', $this->decimal($rate));
        $scheme = $this->cac($category, 'TaxScheme');
        $this->cbc($scheme, 'ID', $code);
        $this->cbc($scheme, 'Name', self::TRIBUTES[$code] ?? throw new LogicException("Unknown DIAN tribute code {$code}."));
    }

    /**
     * Address with its DIVIPOLA codes (FAJ08 to FAJ18, FAJ28 to FAJ38). In Colombia it carries
     * the municipality and department; abroad only the free address and the country.
     *
     * @param  array<string, mixed>  $party
     */
    private function address(DOMElement $parent, string $element, array $party): void
    {
        $address = $this->cac($parent, $element);
        $municipality = $party['municipality'] ?? null;
        $inColombia = $party['country_code'] === self::COLOMBIA;

        if ($inColombia && $municipality !== null) {
            $this->cbc($address, 'ID', $municipality['code']);
            $this->cbc($address, 'CityName', $municipality['name']);
        }

        if (filled($party['postal_code'])) {
            $this->cbc($address, 'PostalZone', $party['postal_code']);
        }

        if ($inColombia && $municipality !== null) {
            $this->cbc($address, 'CountrySubentity', $municipality['department_name']);
            $this->cbc($address, 'CountrySubentityCode', $municipality['department_code']);
        }

        if (filled($party['address'])) {
            $this->cbc($this->cac($address, 'AddressLine'), 'Line', $party['address']);
        }

        $country = $this->cac($address, 'Country');
        $this->cbc($country, 'IdentificationCode', $party['country_code']);

        if ($inColombia) {
            $this->cbc($country, 'Name', 'Colombia', ['languageID' => 'es']);
        }
    }

    /**
     * A customer gets address groups when it is in Colombia with a municipality, or abroad with an address.
     *
     * @param  array<string, mixed>  $party
     */
    private function hasAddress(array $party): bool
    {
        return $party['country_code'] === self::COLOMBIA
            ? ($party['municipality'] ?? null) !== null
            : filled($party['address']);
    }

    /**
     * cbc:CompanyID with the DIAN agency, the identification type and, for a NIT, its check digit.
     *
     * @param  array<string, mixed>  $party
     */
    private function companyId(DOMElement $parent, array $party): void
    {
        $this->cbc($parent, 'CompanyID', $party['identification_number'], [
            ...$this->dianAgency(),
            ...$this->identificationScheme($party),
        ]);
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array<string, string> @schemeID (check digit, only for a NIT) and @schemeName (identification type).
     */
    private function identificationScheme(array $party): array
    {
        $scheme = [];

        if ($party['identification_type'] === IdentificationType::Nit->value && $party['check_digit'] !== null) {
            $scheme['schemeID'] = (string) $party['check_digit'];
        }

        $scheme['schemeName'] = $party['identification_type'];

        return $scheme;
    }

    private function partyTaxScheme(DOMElement $parent, string $code): void
    {
        $scheme = $this->cac($parent, 'TaxScheme');
        $this->cbc($scheme, 'ID', $code);
        $this->cbc($scheme, 'Name', self::PARTY_TAX_SCHEMES[$code] ?? throw new LogicException("Unknown party tax scheme {$code}."));
    }

    /**
     * cac:Contact with the phone (digits only, up to the ten the annex allows) and the email.
     *
     * @param  array<string, mixed>  $data
     */
    private function contact(DOMElement $party, array $data): void
    {
        $phone = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
        $phone = strlen($phone) >= 1 && strlen($phone) <= 10 ? $phone : null;

        if ($phone === null && blank($data['email'])) {
            return;
        }

        $contact = $this->cac($party, 'Contact');

        if ($phone !== null) {
            $this->cbc($contact, 'Telephone', $phone);
        }

        if (filled($data['email'])) {
            $this->cbc($contact, 'ElectronicMail', $data['email']);
        }
    }

    /**
     * @param  Collection<int, DocumentTax>  $taxes
     * @return array<string, list<array{taxable: string, amount: string, rate: string}>>
     */
    private function taxGroups(Collection $taxes): array
    {
        $groups = [];

        foreach ($taxes as $tax) {
            $groups[$tax->dian_code][] = ['taxable' => $tax->taxable_amount, 'amount' => $tax->tax_amount, 'rate' => $tax->rate];
        }

        return $groups;
    }

    /**
     * @param  Collection<int, DocumentWithholding>  $withholdings
     * @return array<string, list<array{taxable: string, amount: string, rate: string}>>
     */
    private function withholdingGroups(Collection $withholdings): array
    {
        $groups = [];

        foreach ($withholdings->sortBy('dian_code') as $withholding) {
            $groups[$withholding->dian_code][] = ['taxable' => $withholding->base_amount, 'amount' => $withholding->amount, 'rate' => $withholding->rate];
        }

        return $groups;
    }

    /**
     * A decimal with at least two decimals and no trailing zeros beyond them: "19.0000" is
     * "19.00", "2.5000" is "2.50" (the DIAN code lists compare rates as these strings) and a
     * price of "250000.500000" is "250000.50".
     */
    private function decimal(string $value): string
    {
        $decimal = BigDecimal::of($value);

        return (string) $decimal->toScale(max(2, $decimal->strippedOfTrailingZeros()->getScale()));
    }

    /**
     * @param  list<string>  $values
     */
    private function sum(array $values): string
    {
        return (string) array_reduce($values, fn (BigDecimal $total, string $value): BigDecimal => $total->plus($value), BigDecimal::zero()->toScale(2));
    }

    /**
     * Splits a free text into pieces the annex allows for elements that can repeat.
     *
     * @return list<string>
     */
    private function chunks(?string $text, int $length): array
    {
        $text = trim((string) $text);

        return $text === '' ? [] : mb_str_split($text, $length);
    }

    /** @return array<string, string> */
    private function dianAgency(): array
    {
        return ['schemeAgencyID' => self::DIAN_AGENCY_ID, 'schemeAgencyName' => self::DIAN_AGENCY_NAME];
    }

    private function amount(DOMElement $parent, string $name, string $value): DOMElement
    {
        return $this->cbc($parent, $name, $value, ['currencyID' => $this->currency]);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function cbc(DOMElement $parent, string $name, ?string $value = null, array $attributes = []): DOMElement
    {
        return $this->element($parent, self::NS_CBC, "cbc:{$name}", $value, $attributes);
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        return $this->element($parent, self::NS_CAC, "cac:{$name}");
    }

    private function ext(DOMElement $parent, string $name): DOMElement
    {
        return $this->element($parent, self::NS_EXT, "ext:{$name}");
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function sts(DOMElement $parent, string $name, ?string $value = null, array $attributes = []): DOMElement
    {
        return $this->element($parent, self::NS_STS, "sts:{$name}", $value, $attributes);
    }

    /**
     * Text goes in as a text node, so characters such as & and < are escaped.
     *
     * @param  array<string, string>  $attributes
     */
    private function element(DOMElement $parent, string $namespace, string $qualifiedName, ?string $value = null, array $attributes = []): DOMElement
    {
        $element = $this->dom->createElementNS($namespace, $qualifiedName);

        foreach ($attributes as $attribute => $attributeValue) {
            $element->setAttribute($attribute, $attributeValue);
        }

        if ($value !== null) {
            $element->appendChild($this->dom->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }
}
