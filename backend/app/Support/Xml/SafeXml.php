<?php

namespace App\Support\Xml;

use DOMDocument;

/**
 * The one way the backend parses XML: what it signs and validates, the SOAP answers and the
 * ApplicationResponses of the DIAN (here the local simulator), and the documents the checks read.
 * Nothing comes from the network (LIBXML_NONET) and no DTD is accepted at all: a document with a
 * DOCTYPE is refused before libxml sees it and again after parsing, so there is no external
 * subset to load and no entity to declare or expand. LIBXML_NOENT, LIBXML_DTDLOAD, LIBXML_DTDATTR
 * and LIBXML_PARSEHUGE are never passed, so libxml keeps its own limits on entity amplification.
 * UBL 2.1 documents, SOAP 1.2 envelopes (which forbid a DTD) and ApplicationResponses carry none.
 */
final class SafeXml
{
    /**
     * @throws XmlRefused When the text declares a DTD or is not well-formed XML. The libxml errors
     *                    stay in its buffer for a caller that collects them (XsdValidator).
     */
    public static function load(string $xml, bool $preserveWhiteSpace = true): DOMDocument
    {
        if (trim($xml) === '') {
            throw new XmlRefused('The XML is empty.');
        }

        if (self::declaresDtd($xml)) {
            throw new XmlRefused('The XML declares a DTD (<!DOCTYPE>), which is not accepted.');
        }

        $dom = new DOMDocument;
        $dom->preserveWhiteSpace = $preserveWhiteSpace;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET);
        } finally {
            if (! $previous) {
                libxml_clear_errors();
            }

            libxml_use_internal_errors($previous);
        }

        if (! $loaded) {
            throw new XmlRefused('The XML is not well formed.');
        }

        // A DOCTYPE written in another encoding, such as UTF-16, is only seen once parsed.
        if ($dom->doctype !== null) {
            throw new XmlRefused('The XML declares a DTD (<!DOCTYPE>), which is not accepted.');
        }

        return $dom;
    }

    /** Whether the raw text has a document type declaration, the only place entities can be declared. */
    public static function declaresDtd(string $xml): bool
    {
        return stripos($xml, '<!DOCTYPE') !== false;
    }
}
