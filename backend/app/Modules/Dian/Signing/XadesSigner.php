<?php

namespace App\Modules\Dian\Signing;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * XAdES-EPES enveloped signature of a UBL document, as section 10 of the DIAN annex 1.9 (signature
 * policy v2) and the signed generic invoice example of the toolbox (Generica.xml) lay it out:
 * ds:Signature in the empty UBLExtension reserved for it, Canonical XML 1.0 without comments,
 * RSA-SHA256, SHA-256 digests and three references (the whole document with the enveloped
 * signature transform, KeyInfo and SignedProperties). Identifiers follow the example:
 * xmldsig-{uuid}, -ref0, -sigvalue, -signedprops, and xmldsig-{uuid}-keyinfo.
 *
 * Pure: DOM and ext-openssl only. It takes the XML, the credentials and the signing time and
 * returns the signed XML; it reads no clock, database or configuration.
 */
final class XadesSigner
{
    public const string NS_DS = 'http://www.w3.org/2000/09/xmldsig#';

    public const string NS_XADES = 'http://uri.etsi.org/01903/v1.3.2#';

    private const string NS_EXT = 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2';

    private const string C14N = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';

    private const string RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    private const string SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';

    private const string ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    private const string SIGNED_PROPERTIES_TYPE = 'http://uri.etsi.org/01903#SignedProperties';

    /** Signature policy (annex 1.9, section 10.10). The digest is the one of the official examples. */
    public const string POLICY_IDENTIFIER = 'https://facturaelectronica.dian.gov.co/politicadefirma/v2/politicadefirmav2.pdf';

    public const string POLICY_DESCRIPTION = 'Política de firma para facturas electrónicas de la República de Colombia';

    public const string POLICY_DIGEST = 'dMoMvtcG5aIzgYo0tIsSQeVJBDnUnfSOfBpxXrmor0Y=';

    /** Section 10.12: the electronic invoicer signs its own documents. */
    public const string SIGNER_ROLE = 'supplier';

    /** Signing time is written in Colombian time, with milliseconds and offset, as in the example. */
    private const string TIMEZONE = 'America/Bogota';

    /** Base64 line length of SignatureValue and X509Certificate, as in the example. */
    private const int BASE64_LINE = 76;

    private DOMDocument $dom;

    public function sign(string $xml, SigningCredentials $credentials, DateTimeInterface $signingTime): string
    {
        $key = openssl_pkey_get_private($credentials->privateKeyPem);

        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('The signing key is not an RSA private key.');
        }

        if (! openssl_x509_check_private_key($credentials->certificatePem, $key)) {
            throw new RuntimeException('The private key does not belong to the signing certificate.');
        }

        $certificate = X509::der($credentials->certificatePem);
        $chain = array_map(fn (string $pem): string => X509::der($pem), $credentials->chainPems);

        $this->dom = new DOMDocument;
        $this->dom->preserveWhiteSpace = true;

        if (! $this->dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('The XML to sign is not well formed.');
        }

        $id = 'xmldsig-'.$this->uuid();
        $keyInfoId = 'xmldsig-'.$this->uuid().'-keyinfo';
        $signedPropertiesId = $id.'-signedprops';

        // The signature goes alone into the reserved extension, so removing it leaves the
        // extension exactly as it was before signing.
        $signature = $this->ds($this->emptySlot(), 'Signature', attributes: ['Id' => $id]);
        $this->newline($signature);

        $signedInfo = $this->ds($signature, 'SignedInfo');
        $this->newline($signedInfo);
        $this->ds($signedInfo, 'CanonicalizationMethod', attributes: ['Algorithm' => self::C14N]);
        $this->newline($signedInfo);
        $this->ds($signedInfo, 'SignatureMethod', attributes: ['Algorithm' => self::RSA_SHA256]);
        $this->newline($signedInfo);
        $documentDigest = $this->reference($signedInfo, ['Id' => $id.'-ref0', 'URI' => ''], enveloped: true);
        $keyInfoDigest = $this->reference($signedInfo, ['URI' => '#'.$keyInfoId]);
        $propertiesDigest = $this->reference($signedInfo, ['Type' => self::SIGNED_PROPERTIES_TYPE, 'URI' => '#'.$signedPropertiesId]);
        $this->newline($signature);

        $signatureValue = $this->ds($signature, 'SignatureValue', attributes: ['Id' => $id.'-sigvalue']);
        $this->newline($signature);

        $keyInfo = $this->keyInfo($signature, $keyInfoId, $certificate);
        $this->newline($signature);

        $object = $this->ds($signature, 'Object');
        $signedProperties = $this->signedProperties($object, $id, $signedPropertiesId, $signingTime, [$certificate, ...$chain]);
        $this->newline($signature);

        // Digests of the three references, then the signature of the canonical SignedInfo.
        $propertiesDigest->appendChild($this->dom->createTextNode($this->digest($signedProperties->C14N(false, false))));
        $keyInfoDigest->appendChild($this->dom->createTextNode($this->digest($keyInfo->C14N(false, false))));
        $documentDigest->appendChild($this->dom->createTextNode($this->digest($this->withoutSignature($id))));

        if (! openssl_sign($signedInfo->C14N(false, false), $value, $key, OPENSSL_ALGO_SHA256) || ! is_string($value)) {
            throw new RuntimeException('The XML could not be signed: '.openssl_error_string());
        }

        $signatureValue->appendChild($this->dom->createTextNode($this->base64Lines($value)));

        // No reformatting: the bytes written must be the ones that were digested.
        $this->dom->formatOutput = false;

        return $this->dom->saveXML() ?: throw new RuntimeException('The signed XML could not be written.');
    }

    /** The ExtensionContent left empty for the signature by the XML builder. */
    private function emptySlot(): DOMElement
    {
        $xpath = new DOMXPath($this->dom);
        $xpath->registerNamespace('ext', self::NS_EXT);
        $xpath->registerNamespace('ds', self::NS_DS);

        $signatures = $xpath->query('//ds:Signature');

        if ($signatures !== false && $signatures->length > 0) {
            throw new RuntimeException('The XML is already signed.');
        }

        $slots = $xpath->query('/*/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent[not(*)]');
        $slot = $slots === false ? null : $slots->item(0);

        if (! $slot instanceof DOMElement) {
            throw new RuntimeException('The XML has no empty UBLExtension reserved for the signature.');
        }

        return $slot;
    }

    /**
     * A ds:Reference with its digest method; returns the DigestValue, to be filled in later.
     *
     * @param  array<string, string>  $attributes
     */
    private function reference(DOMElement $signedInfo, array $attributes, bool $enveloped = false): DOMElement
    {
        $reference = $this->ds($signedInfo, 'Reference', attributes: $attributes);
        $this->newline($reference);

        if ($enveloped) {
            $transforms = $this->ds($reference, 'Transforms');
            $this->newline($transforms);
            $this->ds($transforms, 'Transform', attributes: ['Algorithm' => self::ENVELOPED_SIGNATURE]);
            $this->newline($transforms);
            $this->newline($reference);
        }

        $this->ds($reference, 'DigestMethod', attributes: ['Algorithm' => self::SHA256]);
        $this->newline($reference);
        $digest = $this->ds($reference, 'DigestValue');
        $this->newline($reference);
        $this->newline($signedInfo);

        return $digest;
    }

    private function keyInfo(DOMElement $signature, string $id, string $certificate): DOMElement
    {
        $keyInfo = $this->ds($signature, 'KeyInfo', attributes: ['Id' => $id]);
        $this->newline($keyInfo);
        $data = $this->ds($keyInfo, 'X509Data');
        $this->newline($data);
        $this->ds($data, 'X509Certificate', $this->base64Lines($certificate));
        $this->newline($data);
        $this->newline($keyInfo);

        return $keyInfo;
    }

    /**
     * xades:QualifyingProperties with the signing time, the signing certificate and its chain
     * (digest, issuer and serial of each), the DIAN policy and the signer role.
     *
     * @param  list<string>  $certificates  DER, the signing certificate first.
     */
    private function signedProperties(DOMElement $object, string $signatureId, string $id, DateTimeInterface $signingTime, array $certificates): DOMElement
    {
        $qualifying = $this->xades($object, 'QualifyingProperties', attributes: ['Target' => '#'.$signatureId]);
        $signedProperties = $this->xades($qualifying, 'SignedProperties', attributes: ['Id' => $id]);
        $properties = $this->xades($signedProperties, 'SignedSignatureProperties');

        $time = DateTimeImmutable::createFromInterface($signingTime)->setTimezone(new DateTimeZone(self::TIMEZONE));
        $this->xades($properties, 'SigningTime', $time->format('Y-m-d\TH:i:s.vP'));

        $signingCertificate = $this->xades($properties, 'SigningCertificate');

        foreach ($certificates as $der) {
            $cert = $this->xades($signingCertificate, 'Cert');
            $digest = $this->xades($cert, 'CertDigest');
            $this->ds($digest, 'DigestMethod', attributes: ['Algorithm' => self::SHA256]);
            $this->ds($digest, 'DigestValue', X509::digest($der));
            $issuerSerial = $this->xades($cert, 'IssuerSerial');
            $this->ds($issuerSerial, 'X509IssuerName', X509::issuerName($der));
            $this->ds($issuerSerial, 'X509SerialNumber', X509::serialNumber($der));
        }

        $policyIdentifier = $this->xades($properties, 'SignaturePolicyIdentifier');
        $policy = $this->xades($policyIdentifier, 'SignaturePolicyId');
        $policyId = $this->xades($policy, 'SigPolicyId');
        $this->xades($policyId, 'Identifier', self::POLICY_IDENTIFIER);
        $this->xades($policyId, 'Description', self::POLICY_DESCRIPTION);
        $policyHash = $this->xades($policy, 'SigPolicyHash');
        $this->ds($policyHash, 'DigestMethod', attributes: ['Algorithm' => self::SHA256]);
        $this->ds($policyHash, 'DigestValue', self::POLICY_DIGEST);

        $role = $this->xades($properties, 'SignerRole');
        $this->xades($this->xades($role, 'ClaimedRoles'), 'ClaimedRole', self::SIGNER_ROLE);

        return $signedProperties;
    }

    /**
     * The canonical document after the enveloped-signature transform: the whole document minus
     * the ds:Signature element and everything in it.
     */
    private function withoutSignature(string $signatureId): string
    {
        $copy = $this->dom->cloneNode(true);

        if (! $copy instanceof DOMDocument) {
            throw new RuntimeException('The document could not be copied.');
        }

        $xpath = new DOMXPath($copy);
        $xpath->registerNamespace('ds', self::NS_DS);
        $matches = $xpath->query("//ds:Signature[@Id='{$signatureId}']");
        $signature = $matches === false ? null : $matches->item(0);

        if (! $signature instanceof DOMElement || $signature->parentNode === null) {
            throw new RuntimeException('The signature could not be found in the document.');
        }

        $signature->parentNode->removeChild($signature);

        return $copy->C14N(false, false) ?: throw new RuntimeException('The document could not be canonicalized.');
    }

    private function digest(string|false $canonical): string
    {
        if ($canonical === false) {
            throw new RuntimeException('An element could not be canonicalized.');
        }

        return base64_encode(hash('sha256', $canonical, true));
    }

    /** Base64 in lines, starting and ending with a line break, as the example writes it. */
    private function base64Lines(string $bytes): string
    {
        return "\n".chunk_split(base64_encode($bytes), self::BASE64_LINE, "\n");
    }

    private function newline(DOMElement $parent): void
    {
        $parent->appendChild($this->dom->createTextNode("\n"));
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function ds(DOMElement $parent, string $name, ?string $text = null, array $attributes = []): DOMElement
    {
        return $this->element($parent, self::NS_DS, "ds:{$name}", $text, $attributes);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function xades(DOMElement $parent, string $name, ?string $text = null, array $attributes = []): DOMElement
    {
        return $this->element($parent, self::NS_XADES, "xades:{$name}", $text, $attributes);
    }

    /**
     * Appended before its content is added, so a prefix the document already declares is reused
     * instead of being declared again.
     *
     * @param  array<string, string>  $attributes
     */
    private function element(DOMElement $parent, string $namespace, string $qualifiedName, ?string $text, array $attributes): DOMElement
    {
        $element = $this->dom->createElementNS($namespace, $qualifiedName);
        $parent->appendChild($element);

        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }

        if ($text !== null) {
            $element->appendChild($this->dom->createTextNode($text));
        }

        return $element;
    }

    /** A random version 4 UUID. */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
