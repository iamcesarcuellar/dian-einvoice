<?php

namespace App\Modules\Delivery\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Numbers, money and dates the way the web app shows them (lib/format.ts): dot for thousands,
 * comma for decimals, $ before the amount, dd/mm/yyyy. Done on decimal strings, never floats.
 */
final class ColombianFormat
{
    private const string THOUSANDS = '.';

    private const string DECIMALS = ',';

    /** "1234567.5" becomes "$ 1.234.567,50"; prices keep up to 6 decimals when they have them. */
    public static function money(string $value, int $maxFraction = 2): string
    {
        return '$ '.self::decimal($value, 2, $maxFraction);
    }

    /** "1234567.500000" becomes "1.234.567,5"; at least $minFraction and at most $maxFraction decimals. */
    public static function decimal(string $value, int $minFraction = 0, int $maxFraction = 6): string
    {
        $decimal = BigDecimal::of($value)->toScale($maxFraction, RoundingMode::HalfEven)->strippedOfTrailingZeros();

        if ($decimal->getScale() < $minFraction) {
            $decimal = $decimal->toScale($minFraction);
        }

        $text = (string) $decimal->abs();
        [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', self::THOUSANDS, $integer) ?? $integer;

        return ($decimal->isNegative() ? '-' : '').$grouped.($fraction !== '' ? self::DECIMALS.$fraction : '');
    }

    /** "19.0000" becomes "19 %", "2.5000" "2,5 %". */
    public static function percent(string $value): string
    {
        return self::decimal($value, 0, 4).' %';
    }

    /** A percentage per thousand, as ReteICA rates are given: "0.9660" (percent) becomes "9,66 ‰". */
    public static function perMil(string $value): string
    {
        return self::decimal((string) BigDecimal::of($value)->multipliedBy(10), 0, 3).' ‰';
    }

    /** "990000001" with check digit 3 becomes "990.000.001-3". */
    public static function nit(string $number, int|string|null $checkDigit): string
    {
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', self::THOUSANDS, $number) ?? $number;

        return $checkDigit === null || $checkDigit === '' ? $grouped : "{$grouped}-{$checkDigit}";
    }

    /** A calendar date as dd/mm/yyyy, without going through time zones. */
    public static function date(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof CarbonInterface ? $value->toDateString() : substr($value, 0, 10);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match) === 1 ? "{$match[3]}/{$match[2]}/{$match[1]}" : $date;
    }

    /** An instant in Colombian time: "18/11/2026 10:21:30". */
    public static function dateTime(CarbonInterface $value, string $timezone = 'America/Bogota'): string
    {
        $local = Carbon::instance($value)->setTimezone($timezone);

        return self::date($local->toDateString()).' '.$local->format('H:i:s');
    }
}
