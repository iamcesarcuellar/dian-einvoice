<?php

namespace App\Modules\Developers\Idempotency;

/**
 * The fingerprint an Idempotency-Key is kept with: SHA-256 of the method, the path and the body,
 * a JSON body in a canonical form (objects with their keys sorted at every level, lists in their
 * order, no spacing), so the same request written another way is still the same request. Pure PHP.
 */
final class IdempotencyFingerprint
{
    public static function of(string $method, string $path, string $content): string
    {
        $decoded = json_decode($content, true);
        $body = json_last_error() === JSON_ERROR_NONE && $content !== ''
            ? json_encode(self::canonical($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $content;

        return hash('sha256', $method."\n".$path."\n".$body);
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
