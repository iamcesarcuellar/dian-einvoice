<?php

namespace App\Modules\Delivery\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use NumberFormatter;
use RuntimeException;

/**
 * An amount in pesos written out in the current language (ICU spell-out, ext-intl): "One million
 * two hundred thousand Colombian pesos", with the cents as "and 50/100". The sentence comes from
 * the translation keys delivery::pdf.words and delivery::pdf.words_cents.
 */
final class AmountInWords
{
    public static function pesos(string $amount, ?string $locale = null): string
    {
        $decimal = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfEven)->abs();
        [$integer, $cents] = explode('.', (string) $decimal, 2);

        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::SPELLOUT);
        $words = $formatter->format((int) $integer);

        if ($words === false) {
            throw new RuntimeException("The amount {$amount} could not be written out.");
        }

        $words = mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);

        return $cents === '00'
            ? __('delivery::pdf.words', ['words' => $words])
            : __('delivery::pdf.words_cents', ['words' => $words, 'cents' => $cents]);
    }
}
