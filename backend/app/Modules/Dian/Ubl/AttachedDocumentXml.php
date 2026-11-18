<?php

namespace App\Modules\Dian\Ubl;

use App\Modules\Billing\Models\Document;
use App\Modules\Reference\Enums\FiscalResponsibility;
use App\Modules\Reference\Enums\IdentificationType;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Builds the unsigned AttachedDocument, the electronic container delivered to the customer, as
 * section 6.4 of annex 1.9 (AE01 to AE55) and its example lay it out: header literals, the issuer
 * as SenderParty and the customer as ReceiverParty from the frozen copies, the signed invoice in
 * cac:Attachment and the ApplicationResponse of its validation in the ParentDocumentLineReference,
 * both as CDATA. Element order follows UBL-AttachedDocument-2.1.xsd. It keeps one empty
 * UBLExtension for the XAdES-EPES signature the annex requires (FAC01 to FAC03).
 *
 * In this project the validation comes from the local DIAN simulator, so ValidatorID says so
 * instead of the literal of AE52, which names the DIAN.
 */
final class AttachedDocumentXml
{
    public const string NS_ATTACHED = 'urn:oasis:names:specification:ubl:schema:xsd:AttachedDocument-2';

    public const string NS_CCTS = 'urn:un:unece:uncefact:data:specification:CoreComponentTypeSchemaModule:2';

    /** AE52 when the DIAN validated the document. */
    public const string DIAN_VALIDATOR = 'Unidad Especial Dirección de Impuestos y Aduanas Nacionales';

    /** ValidatorID when the local simulator validated it, which is always the case in this project. */
    public const string SIMULATOR_VALIDATOR = 'Local DIAN simulator of dian-einvoice (development tool, not the DIAN)';

    /** Literals of AE02, AE03, AE04, AE08, AE45, AE35/AE48 and AE36/AE49. */
    private const string UBL_VERSION = 'UBL 2.1';

    private const string CUSTOMIZATION = 'Documentos adjuntos';

    private const string PROFILE = 'Factura Electrónica de Venta';

    private const string DOCUMENT_TYPE = 'Contenedor de Factura Electrónica';

    private const string RESPONSE_TYPE = 'ApplicationResponse';

    private const string MIME_CODE = 'text/xml';

    private const string ENCODING_CODE = 'UTF-8';

    /** AE43: CUFE-SHA384 for an invoice, CUDE-SHA384 for a credit or debit note. */
    private const string CUFE_SCHEME = 'CUFE-SHA384';

    private const string CUDE_SCHEME = 'CUDE-SHA384';

    private const string TIMEZONE = 'America/Bogota';

    private DOMDocument $dom;

    public function build(Document $document, AttachedDocumentInput $input): string
    {
        if ($document->isDraft() || $document->cufe === null || $document->full_number === null) {
            throw new LogicException('Only issued documents with a CUFE or CUDE have an AttachedDocument.');
        }

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->xmlStandalone = false;
        $this->dom->formatOutput = true;

        $root = $this->dom->createElementNS(self::NS_ATTACHED, 'AttachedDocument');
        $this->dom->appendChild($root);

        // The declarations of the annex example; ds and xades are used by the signature.
        foreach (['ds' => UblDocumentXml::NS_DS, 'cac' => UblDocumentXml::NS_CAC, 'cbc' => UblDocumentXml::NS_CBC, 'ccts' => self::NS_CCTS, 'ext' => UblDocumentXml::NS_EXT, 'xades' => UblDocumentXml::NS_XADES, 'xades141' => UblDocumentXml::NS_XADES141] as $prefix => $namespace) {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:{$prefix}", $namespace);
        }

        // FAC01 to FAC03: the extension the XAdES signer fills with ds:Signature.
        $this->element($this->element($this->element($root, UblDocumentXml::NS_EXT, 'ext:UBLExtensions'), UblDocumentXml::NS_EXT, 'ext:UBLExtension'), UblDocumentXml::NS_EXT, 'ext:ExtensionContent');

        $issuedAt = DateTimeImmutable::createFromInterface($input->issuedAt)->setTimezone(new DateTimeZone(self::TIMEZONE));

        $this->cbc($root, 'UBLVersionID', self::UBL_VERSION);
        $this->cbc($root, 'CustomizationID', self::CUSTOMIZATION);
        $this->cbc($root, 'ProfileID', self::PROFILE);
        $this->cbc($root, 'ProfileExecutionID', (string) $document->dian_environment);
        $this->cbc($root, 'ID', $input->id);
        $this->cbc($root, 'IssueDate', $issuedAt->format('Y-m-d'));
        $this->cbc($root, 'IssueTime', $issuedAt->format('H:i:sP'));
        $this->cbc($root, 'DocumentType', self::DOCUMENT_TYPE);
        $this->cbc($root, 'ParentDocumentID', $document->full_number);

        // AE09 to AE32: who generates the container (the issuer) and who receives it (the customer).
        $this->party($root, 'SenderParty', $document->company_snapshot);
        $this->party($root, 'ReceiverParty', $document->customer_snapshot);

        // AE33 to AE37: the signed invoice.
        $this->attachment($root, $input->invoiceXml);

        // AE38 to AE55: the ApplicationResponse of the validation, with its result.
        $line = $this->cac($root, 'ParentDocumentLineReference');
        $this->cbc($line, 'LineID', '1');
        $reference = $this->cac($line, 'DocumentReference');
        $this->cbc($reference, 'ID', $document->full_number);
        $this->cbc($reference, 'UUID', $document->cufe, ['schemeName' => $document->isNote() ? self::CUDE_SCHEME : self::CUFE_SCHEME]);
        $this->cbc($reference, 'IssueDate', $input->response->issueDate);
        $this->cbc($reference, 'DocumentType', self::RESPONSE_TYPE);
        $this->attachment($reference, $input->responseXml);

        $verification = $this->cac($reference, 'ResultOfVerification');
        $this->cbc($verification, 'ValidatorID', $input->validator);
        $this->cbc($verification, 'ValidationResultCode', $input->response->responseCode);
        $this->cbc($verification, 'ValidationDate', $input->response->issueDate);
        $this->cbc($verification, 'ValidationTime', $input->response->issueTime);

        return $this->dom->saveXML() ?: throw new LogicException('The AttachedDocument could not be written.');
    }

    /**
     * cac:PartyTaxScheme of a frozen party: name, identification (with the check digit of a NIT),
     * fiscal responsibilities and tax scheme.
     *
     * @param  array<string, mixed>  $party
     */
    private function party(DOMElement $root, string $element, array $party): void
    {
        $scheme = $this->cac($this->cac($root, $element), 'PartyTaxScheme');
        $this->cbc($scheme, 'RegistrationName', (string) $party['legal_name']);

        $attributes = ['schemeAgencyID' => UblDocumentXml::DIAN_AGENCY_ID];

        if ($party['identification_type'] === IdentificationType::Nit->value && $party['check_digit'] !== null) {
            $attributes['schemeID'] = (string) $party['check_digit'];
        }

        $attributes['schemeName'] = (string) $party['identification_type'];
        $this->cbc($scheme, 'CompanyID', (string) $party['identification_number'], $attributes);

        $responsibilities = $party['fiscal_responsibilities'] ?? [];
        $this->cbc($scheme, 'TaxLevelCode', $responsibilities === [] ? FiscalResponsibility::NotApplicable->value : implode(';', $responsibilities));

        $code = (string) ($party['tax_scheme'] ?? 'ZZ');
        $taxScheme = $this->cac($scheme, 'TaxScheme');
        $this->cbc($taxScheme, 'ID', $code);
        $this->cbc($taxScheme, 'Name', UblDocumentXml::PARTY_TAX_SCHEMES[$code] ?? throw new LogicException("Unknown party tax scheme {$code}."));
    }

    /** cac:Attachment with an XML document as CDATA, untouched. */
    private function attachment(DOMElement $parent, string $xml): void
    {
        $reference = $this->cac($this->cac($parent, 'Attachment'), 'ExternalReference');
        $this->cbc($reference, 'MimeCode', self::MIME_CODE);
        $this->cbc($reference, 'EncodingCode', self::ENCODING_CODE);

        $description = $this->cbc($reference, 'Description');

        // "]]>" cannot appear inside one CDATA section; where it does, it is split across two.
        $pieces = explode(']]>', $xml);
        $last = count($pieces) - 1;

        foreach ($pieces as $index => $piece) {
            $description->appendChild($this->dom->createCDATASection(($index > 0 ? '>' : '').$piece.($index < $last ? ']]' : '')));
        }
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function cbc(DOMElement $parent, string $name, ?string $value = null, array $attributes = []): DOMElement
    {
        return $this->element($parent, UblDocumentXml::NS_CBC, "cbc:{$name}", $value, $attributes);
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        return $this->element($parent, UblDocumentXml::NS_CAC, "cac:{$name}");
    }

    /**
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
