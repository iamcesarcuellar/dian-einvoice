<?php

namespace App\Modules\Delivery\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Numbers, money and dates the way the web app shows them (lib/format.ts): dot for thousands,
 * comma for decimals, $ before the amount, and dates as the language of the app writes them
 * (delivery::format), "19 Nov 2026" in English. Done on decimal strings, never floats.
 */
final class ColombianFormat
{
    private const string THOUSANDS = '.';

    private const string DECIMALS = ',';

    /**
     * "1234567.5" becomes "$ 1.234.567,50"; prices keep up to 6 decimals when they have them.
     *
     * @param  non-negative-int  $maxFraction
     */
    public static function money(string $value, int $maxFraction = 2): string
    {
        return '$ '.self::decimal($value, 2, $maxFraction);
    }

    /**
     * "1234567.500000" becomes "1.234.567,5"; at least $minFraction and at most $maxFraction decimals.
     *
     * @param  non-negative-int  $minFraction
     * @param  non-negative-int  $maxFraction
     */
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

    /**
     * A calendar date as the language of the app writes it, without going through time zones:
     * "19 Nov 2026" in English, the month in letters so that no reader takes the day for the month.
     * The pattern and the month names are delivery::format.date and delivery::format.months.
     */
    public static function date(CarbonInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $date = $value instanceof CarbonInterface ? $value->toDateString() : substr($value, 0, 10);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match) !== 1) {
            return $date;
        }

        return (string) __('delivery::format.date', [
            'day' => (string) (int) $match[3],
            'dd' => $match[3],
            'month' => (string) __("delivery::format.months.{$match[2]}"),
            'mm' => $match[2],
            'year' => $match[1],
        ]);
    }

    /** An instant in Colombian time, to the minute: "19 Nov 2026, 15:00" in English. */
    public static function dateTime(CarbonInterface $value, string $timezone = 'America/Bogota'): string
    {
        $local = Carbon::instance($value)->setTimezone($timezone);

        return (string) __('delivery::format.date_time', ['date' => self::date($local->toDateString()), 'time' => $local->format('H:i')]);
    }
}
