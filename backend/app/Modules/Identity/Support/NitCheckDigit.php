<?php

namespace App\Modules\Identity\Support;

use InvalidArgumentException;

/**
 * Check digit (DV) of a Colombian NIT, with the DIAN modulo 11 algorithm.
 */
final class NitCheckDigit
{
    /** Weights applied to the NIT digits from right to left. */
    private const array WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    public static function calculate(string $nit): int
    {
        if (preg_match('/^\d{1,15}$/', $nit) !== 1) {
            throw new InvalidArgumentException('A NIT has between 1 and 15 digits.');
        }

        $sum = 0;

        foreach (array_reverse(str_split($nit)) as $position => $digit) {
            $sum += (int) $digit * self::WEIGHTS[$position];
        }

        $remainder = $sum % 11;

        return $remainder > 1 ? 11 - $remainder : $remainder;
    }
}
