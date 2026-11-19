<?php

namespace App\Support;

/**
 * Typed reads of decoded JSON (json_decode into arrays): the answers of the local services and,
 * in the check commands, of the API itself. A path takes dots, as data_get() does. A value of
 * another type reads as missing: null, or an empty array.
 */
final class Json
{
    /** A text, a number or a boolean at the path, as text. */
    public static function text(mixed $json, string $path): ?string
    {
        $value = data_get($json, $path);

        return is_scalar($value) ? (string) $value : null;
    }

    /** A whole number at the path, also when it comes as numeric text. */
    public static function int(mixed $json, string $path): ?int
    {
        $value = data_get($json, $path);

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * The object or the array at the path.
     *
     * @return array<mixed>
     */
    public static function array(mixed $json, string $path): array
    {
        $value = data_get($json, $path);

        return is_array($value) ? $value : [];
    }

    /**
     * The objects in the array at the path, in their order.
     *
     * @return list<array<mixed>>
     */
    public static function objects(mixed $json, string $path): array
    {
        return array_values(array_filter(self::array($json, $path), is_array(...)));
    }
}
