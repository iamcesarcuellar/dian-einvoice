<?php

namespace App\Modules\Reference\Support;

/**
 * Turns the upper-case place names of DIVIPOLA into display names:
 * "SAN JOSÉ DE CÚCUTA" becomes "San José de Cúcuta" and "BOGOTÁ, D.C." becomes "Bogotá, D.C.".
 */
final class PlaceName
{
    /** Spanish particles written in lower case inside a name. */
    private const array PARTICLES = ['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e', 'en'];

    public static function format(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        foreach ($words as $index => $word) {
            $lower = mb_strtolower($word);

            if (str_contains($word, '.')) {
                $words[$index] = mb_strtoupper($word);
            } elseif ($index > 0 && in_array($lower, self::PARTICLES, true)) {
                $words[$index] = $lower;
            } else {
                $words[$index] = preg_replace_callback(
                    '/(^|[-(])(\p{L})/u',
                    fn (array $match): string => $match[1].mb_strtoupper($match[2]),
                    $lower,
                ) ?? $lower;
            }
        }

        return implode(' ', $words);
    }
}
