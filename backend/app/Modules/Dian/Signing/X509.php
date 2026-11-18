<?php

namespace App\Modules\Dian\Signing;

use Brick\Math\BigInteger;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * What XAdES needs from an X.509 certificate, read straight from its DER: the issuer name in
 * RFC 2253 form, as the signed examples of the DIAN toolbox write it, the serial number in
 * decimal and the SHA-256 digest. Also re-dates the validity of a certificate this project
 * generates. Pure PHP and ext-openssl.
 */
final class X509
{
    /** RFC 2253 keywords; any other attribute is written as its OID and the hex DER of its value. */
    private const array KEYWORDS = [
        '2.5.4.3' => 'CN',
        '2.5.4.7' => 'L',
        '2.5.4.8' => 'ST',
        '2.5.4.10' => 'O',
        '2.5.4.11' => 'OU',
        '2.5.4.6' => 'C',
        '2.5.4.9' => 'STREET',
        '0.9.2342.19200300.100.1.25' => 'DC',
        '0.9.2342.19200300.100.1.1' => 'UID',
    ];

    public static function der(string $pem): string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $match) !== 1) {
            throw new InvalidArgumentException('Not a PEM certificate.');
        }

        $der = base64_decode(preg_replace('/\s+/', '', $match[1]) ?? '', true);

        if ($der === false || $der === '') {
            throw new InvalidArgumentException('The PEM certificate is not valid base64.');
        }

        return $der;
    }

    public static function pem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /** Base64 of the SHA-256 of the DER, for xades:CertDigest. */
    public static function digest(string $der): string
    {
        return base64_encode(hash('sha256', $der, true));
    }

    /** The serial number in decimal, for ds:X509SerialNumber. */
    public static function serialNumber(string $der): string
    {
        $serial = self::tbsChildren($der)['serial'];

        return (string) BigInteger::fromBytes(Der::content($der, $serial['element']));
    }

    /** The issuer in RFC 2253 form, for ds:X509IssuerName. */
    public static function issuerName(string $der): string
    {
        return self::rfc2253($der, self::tbsChildren($der)['issuer']['element']);
    }

    /** The subject in RFC 2253 form. */
    public static function subjectName(string $der): string
    {
        return self::rfc2253($der, self::tbsChildren($der)['subject']['element']);
    }

    /**
     * Sets the validity of a certificate and signs it again with the issuer key. Both dates must
     * fall in 1950 to 2049 (UTCTime), as they do in the certificate being changed, so every length
     * stays the same and the change is made in place.
     */
    public static function withValidity(string $der, DateTimeInterface $notBefore, DateTimeInterface $notAfter, OpenSSLAsymmetricKey $issuerKey): string
    {
        $certificate = Der::read($der);
        [$tbs, $algorithm, $signature] = Der::children($der, $certificate);
        $validity = self::tbsChildren($der)['validity']['element'];

        $times = Der::children($der, $validity);

        if (count($times) !== 2 || $times[0]['tag'] !== Der::UTC_TIME || $times[1]['tag'] !== Der::UTC_TIME) {
            throw new RuntimeException('Only certificates with UTCTime validity can be re-dated.');
        }

        $utc = new DateTimeZone('UTC');
        $encode = fn (DateTimeInterface $time): string => chr(Der::UTC_TIME).chr(13).DateTimeImmutable::createFromInterface($time)->setTimezone($utc)->format('ymdHis').'Z';

        $replacement = $encode($notBefore).$encode($notAfter);

        if (strlen($replacement) !== $validity['length']) {
            throw new RuntimeException('The new validity does not have the length of the old one.');
        }

        $der = substr_replace($der, $replacement, $validity['contentStart'], $validity['length']);

        // Sign the new TBSCertificate with the algorithm the certificate declares (sha256WithRSAEncryption).
        if (! openssl_sign(Der::bytes($der, $tbs), $value, $issuerKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The certificate could not be signed again: '.openssl_error_string());
        }

        $bitString = "\0".$value;

        if ($signature['tag'] !== Der::BIT_STRING || strlen($bitString) !== $signature['length'] || $algorithm['tag'] !== Der::SEQUENCE) {
            throw new RuntimeException('The new signature does not have the length of the old one.');
        }

        return substr_replace($der, $bitString, $signature['contentStart'], $signature['length']);
    }

    /**
     * Serial, issuer, validity and subject of the TBSCertificate.
     *
     * @return array<string, array{element: array{tag: int, start: int, contentStart: int, length: int, end: int}}>
     */
    private static function tbsChildren(string $der): array
    {
        $certificate = Der::read($der);
        $tbs = Der::children($der, $certificate)[0] ?? throw new InvalidArgumentException('Not an X.509 certificate.');
        $fields = Der::children($der, $tbs);

        // Version is an explicit [0] tag; it is absent in version 1 certificates.
        $offset = ($fields[0]['tag'] ?? null) === 0xA0 ? 1 : 0;

        if (count($fields) < $offset + 6 || $fields[$offset]['tag'] !== Der::INTEGER) {
            throw new InvalidArgumentException('Not an X.509 certificate.');
        }

        return [
            'serial' => ['element' => $fields[$offset]],
            'issuer' => ['element' => $fields[$offset + 2]],
            'validity' => ['element' => $fields[$offset + 3]],
            'subject' => ['element' => $fields[$offset + 4]],
        ];
    }

    /**
     * RFC 2253: relative distinguished names in reverse order, separated by commas.
     *
     * @param  array{tag: int, start: int, contentStart: int, length: int, end: int}  $name
     */
    private static function rfc2253(string $der, array $name): string
    {
        $rdns = [];

        foreach (Der::children($der, $name) as $rdn) {
            $attributes = [];

            foreach (Der::children($der, $rdn) as $attribute) {
                [$type, $value] = Der::children($der, $attribute);
                $attributes[] = self::attribute($der, Der::oid(Der::content($der, $type)), $value);
            }

            $rdns[] = implode('+', $attributes);
        }

        return implode(',', array_reverse($rdns));
    }

    /**
     * @param  array{tag: int, start: int, contentStart: int, length: int, end: int}  $value
     */
    private static function attribute(string $der, string $oid, array $value): string
    {
        $keyword = self::KEYWORDS[$oid] ?? null;
        $text = $keyword === null ? null : self::text($value['tag'], Der::content($der, $value));

        if ($keyword === null || $text === null) {
            return $oid.'=#'.bin2hex(Der::bytes($der, $value));
        }

        return $keyword.'='.self::escape($text);
    }

    /** The UTF-8 text of an ASN.1 string, or null for types that are not strings. */
    private static function text(int $tag, string $content): ?string
    {
        return match ($tag) {
            0x0C, 0x13, 0x16, 0x1A => $content,
            0x14 => mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1'),
            0x1E => mb_convert_encoding($content, 'UTF-8', 'UTF-16BE'),
            0x1C => mb_convert_encoding($content, 'UTF-8', 'UTF-32BE'),
            default => null,
        };
    }

    private static function escape(string $value): string
    {
        $escaped = preg_replace('/([,+"\\\\<>;])/', '\\\\$1', $value) ?? $value;

        if (str_starts_with($escaped, '#') || str_starts_with($escaped, ' ')) {
            $escaped = '\\'.$escaped;
        }

        if (str_ends_with($escaped, ' ')) {
            $escaped = substr($escaped, 0, -1).'\\ ';
        }

        return $escaped;
    }
}
