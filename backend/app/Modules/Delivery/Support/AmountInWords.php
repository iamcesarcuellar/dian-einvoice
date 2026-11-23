<?php

namespace App\Modules\Delivery\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use NumberFormatter;
use RuntimeException;

/**
 * An amount in pesos written out in the current language (ICU spell-out, ext-intl): "One million
 * two hundred thousand Colombian pesos", with the cents as "and 50/100"; in Spanish "Un millón
 * doscientos mil pesos colombianos", with the cents as "con 50/100". The sentence comes from the
 * translation keys delivery::pdf.words and delivery::pdf.words_cents.
 */
final class AmountInWords
{
    public static function pesos(string $amount, ?string $locale = null): string
    {
        $decimal = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfEven)->abs();
        [$integer, $cents] = explode('.', (string) $decimal, 2);

        $locale ??= app()->getLocale();
        $spanish = str_starts_with($locale, 'es');
        $formatter = new NumberFormatter($locale, NumberFormatter::SPELLOUT);

        // Before a noun, Spanish counts in the masculine with its short forms: "un", "veintiún", "ciento un".
        if ($spanish) {
            $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, '%spellout-cardinal-masculine');
        }

        $words = $formatter->format((int) $integer);

        if ($words === false) {
            throw new RuntimeException("The amount {$amount} could not be written out.");
        }

        // ICU marks where long Spanish words may break with soft hyphens ("nove\u{AD}cientos"); a whole million takes
        // "de" before the noun: "un millón de pesos".
        $words = str_replace("\u{AD}", '', $words);

        if ($spanish && preg_match('/(millón|millones)$/u', $words) === 1) {
            $words .= ' de';
        }

        $words = mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);

        return $cents === '00'
            ? __('delivery::pdf.words', ['words' => $words], $locale)
            : __('delivery::pdf.words_cents', ['words' => $words, 'cents' => $cents], $locale);
    }
}
