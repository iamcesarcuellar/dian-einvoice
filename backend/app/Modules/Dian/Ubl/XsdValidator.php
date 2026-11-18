<?php

namespace App\Modules\Dian\Ubl;

use App\Modules\Billing\Enums\DocumentType;
use DOMDocument;
use DOMXPath;
use LibXMLError;

/**
 * Validates an invoice, a credit note or a debit note against the official UBL-Invoice-2.1.xsd,
 * UBL-CreditNote-2.1.xsd or UBL-DebitNote-2.1.xsd of the DIAN toolbox, and an AttachedDocument
 * against UBL-AttachedDocument-2.1.xsd, all kept in resources/dian/xsd. Two
 * adjustments, both on a copy of the document:
 *  - the empty UBLExtension reserved for the signature is left out, because the schema requires
 *    one element in every ExtensionContent; stage 5 fills it with the signature;
 *  - xsi:schemaLocation is ignored, so libxml never reaches the network: the schema is the local copy.
 * The content of sts:DianExtensions is processed "lax" by UBL, which does not import the DIAN
 * structures schema, so the XSD does not cover it.
 */
final class XsdValidator
{
    public function schemaPath(DocumentType $type = DocumentType::Invoice): string
    {
        return resource_path('dian/xsd/maindoc/UBL-'.UblDocumentXml::root($type).'-2.1.xsd');
    }

    public function attachedDocumentSchemaPath(): string
    {
        return resource_path('dian/xsd/maindoc/UBL-AttachedDocument-2.1.xsd');
    }

    public function validateInvoice(string $xml): XsdResult
    {
        return $this->validate($xml, $this->schemaPath());
    }

    /** An issued document against the XSD of its type. */
    public function validateDocument(DocumentType $type, string $xml): XsdResult
    {
        return $this->validate($xml, $this->schemaPath($type));
    }

    public function validateAttachedDocument(string $xml): XsdResult
    {
        return $this->validate($xml, $this->attachedDocumentSchemaPath());
    }

    private function validate(string $xml, string $schema): XsdResult
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument;

            if (! $dom->loadXML($xml, LIBXML_NONET)) {
                return new XsdResult($this->errors(), 0);
            }

            $skipped = $this->detachEmptyExtensions($dom);
            $dom->documentElement?->removeAttributeNS(UblDocumentXml::NS_XSI, 'schemaLocation');

            $dom->schemaValidate($schema);

            return new XsdResult($this->errors(), $skipped);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Removes the extensions whose ExtensionContent has no element yet, and counts them. */
    private function detachEmptyExtensions(DOMDocument $dom): int
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ext', UblDocumentXml::NS_EXT);

        $empty = $xpath->query('/*/ext:UBLExtensions/ext:UBLExtension[ext:ExtensionContent[not(*)]]');
        $count = 0;

        foreach ($empty === false ? [] : $empty as $extension) {
            $extension->parentNode?->removeChild($extension);
            $count++;
        }

        return $count;
    }

    /**
     * Errors and fatal errors only; libxml warnings do not make a document invalid.
     *
     * @return list<string>
     */
    private function errors(): array
    {
        $messages = [];

        foreach (libxml_get_errors() as $error) {
            /** @var LibXMLError $error */
            if ($error->level >= LIBXML_ERR_ERROR) {
                $messages[] = sprintf('Line %d: %s', $error->line, trim($error->message));
            }
        }

        return $messages;
    }
}
