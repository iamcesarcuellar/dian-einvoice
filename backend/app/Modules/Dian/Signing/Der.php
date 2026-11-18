<?php

namespace App\Modules\Dian\Signing;

use InvalidArgumentException;

/**
 * Minimal reader of DER-encoded ASN.1, enough to walk an X.509 certificate. Pure PHP.
 * An element is [tag, start, contentStart, length, end] with byte offsets into the DER string.
 */
final class Der
{
    public const int SEQUENCE = 0x30;

    public const int SET = 0x31;

    public const int INTEGER = 0x02;

    public const int OBJECT_IDENTIFIER = 0x06;

    public const int UTC_TIME = 0x17;

    public const int BIT_STRING = 0x03;

    /**
     * @return array{tag: int, start: int, contentStart: int, length: int, end: int}
     */
    public static function read(string $der, int $offset = 0): array
    {
        $size = strlen($der);

        if ($offset + 2 > $size) {
            throw new InvalidArgumentException('Truncated DER element.');
        }

        $tag = ord($der[$offset]);
        $first = ord($der[$offset + 1]);
        $header = 2;
        $length = $first;

        if ($first & 0x80) {
            $count = $first & 0x7F;

            if ($count < 1 || $count > 4 || $offset + 2 + $count > $size) {
                throw new InvalidArgumentException('Unsupported DER length.');
            }

            $length = 0;

            for ($index = 0; $index < $count; $index++) {
                $length = ($length << 8) | ord($der[$offset + 2 + $index]);
            }

            $header += $count;
        }

        $contentStart = $offset + $header;

        if ($contentStart + $length > $size) {
            throw new InvalidArgumentException('DER element runs past the end of the data.');
        }

        return ['tag' => $tag, 'start' => $offset, 'contentStart' => $contentStart, 'length' => $length, 'end' => $contentStart + $length];
    }

    /**
     * The elements inside a constructed element, such as a SEQUENCE.
     *
     * @param  array{tag: int, start: int, contentStart: int, length: int, end: int}  $element
     * @return list<array{tag: int, start: int, contentStart: int, length: int, end: int}>
     */
    public static function children(string $der, array $element): array
    {
        $children = [];
        $offset = $element['contentStart'];

        while ($offset < $element['end']) {
            $child = self::read($der, $offset);
            $children[] = $child;
            $offset = $child['end'];
        }

        return $children;
    }

    /**
     * The whole element: tag, length and content.
     *
     * @param  array{tag: int, start: int, contentStart: int, length: int, end: int}  $element
     */
    public static function bytes(string $der, array $element): string
    {
        return substr($der, $element['start'], $element['end'] - $element['start']);
    }

    /**
     * @param  array{tag: int, start: int, contentStart: int, length: int, end: int}  $element
     */
    public static function content(string $der, array $element): string
    {
        return substr($der, $element['contentStart'], $element['length']);
    }

    /** Dotted form of an OBJECT IDENTIFIER content, such as 2.5.4.3. */
    public static function oid(string $content): string
    {
        $bytes = array_values(unpack('C*', $content) ?: []);

        if ($bytes === []) {
            throw new InvalidArgumentException('Empty OBJECT IDENTIFIER.');
        }

        $first = $bytes[0];
        $parts = $first >= 80 ? [2, $first - 80] : [intdiv($first, 40), $first % 40];
        $value = 0;

        foreach (array_slice($bytes, 1) as $byte) {
            $value = ($value << 7) | ($byte & 0x7F);

            if (($byte & 0x80) === 0) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }
}
