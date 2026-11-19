<?php

namespace App\Modules\Billing\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Calculates the lines, taxes, withholdings and totals of a document. It is the only place where
 * document arithmetic happens: the API stores its results and the web app only shows them.
 * Pure PHP and brick/math: no framework, no database, no floats.
 */
final class DocumentCalculator
{
    /**
     * Rounding of every amount: half to even, the round-half-to-even of XPath that annex 1.9 adopts
     * (section 5.2.1, NTC 3711). Each line is rounded to two decimals and the totals are exact sums
     * of the lines, so the totals rules (FAU02, FAU04, FAU06, FAU14) hold exactly; a tax subtotal
     * may differ from its base times its rate by half a cent per line, inside the tolerance of two
     * pesos of section 5.2.1.1 for documents of up to 400 lines. The optional rounding of the IVA to
     * the nearest ten pesos (5.2.1.2) is not applied.
     */
    public const RoundingMode ROUNDING = RoundingMode::HalfEven;

    /** Decimals of amounts. */
    public const int SCALE = 2;

    /** Decimals of rates, used to group taxes. */
    private const int RATE_SCALE = 4;

    /** DIAN code of IVA, the base of ReteIVA. */
    private const string IVA = '01';

    /**
     * @param  list<LineInput>  $lines
     * @param  list<WithholdingInput>  $withholdings
     */
    public function calculate(array $lines, array $withholdings): Calculation
    {
        $zero = BigDecimal::zero()->toScale(self::SCALE);

        $subtotal = $discounts = $lineExtension = $taxExclusive = $taxTotal = $iva = $zero;
        $lineResults = [];
        $groups = [];

        foreach ($lines as $line) {
            // Base and discount are rounded first; the line value is their exact difference.
            $base = BigDecimal::of($line->quantity)->multipliedBy($line->unitPrice)->toScale(self::SCALE, self::ROUNDING);
            $discount = $base->multipliedBy($line->discountRate)->dividedBy(100, self::SCALE, self::ROUNDING);
            $value = $base->minus($discount);
            $tax = $zero;

            if ($line->taxCode !== null && $line->taxRate !== null) {
                $rate = BigDecimal::of($line->taxRate)->toScale(self::RATE_SCALE, self::ROUNDING);
                $tax = $value->multipliedBy($rate)->dividedBy(100, self::SCALE, self::ROUNDING);

                $key = $line->taxCode.'|'.$rate;
                $groups[$key] ??= ['code' => $line->taxCode, 'rate' => $rate, 'taxable' => $zero, 'tax' => $zero];
                $groups[$key]['taxable'] = $groups[$key]['taxable']->plus($value);
                $groups[$key]['tax'] = $groups[$key]['tax']->plus($tax);

                $taxExclusive = $taxExclusive->plus($value);
                $taxTotal = $taxTotal->plus($tax);

                if ($line->taxCode === self::IVA) {
                    $iva = $iva->plus($tax);
                }
            }

            $subtotal = $subtotal->plus($base);
            $discounts = $discounts->plus($discount);
            $lineExtension = $lineExtension->plus($value);

            $lineResults[] = new LineResult($base, $discount, $value, $tax);
        }

        $taxes = array_map(
            fn (array $group): TaxResult => new TaxResult($group['code'], $group['rate'], $group['taxable'], $group['tax']),
            array_values($groups),
        );

        // By tax code, then from the highest rate down.
        usort($taxes, fn (TaxResult $a, TaxResult $b): int => strcmp($a->code, $b->code) ?: $b->rate->compareTo($a->rate));

        $withholdingTotal = $zero;
        $withholdingResults = [];

        foreach ($withholdings as $withholding) {
            $withholdingBase = $withholding->onTax ? $iva : $lineExtension;
            $amount = $withholdingBase->multipliedBy($withholding->rate)->dividedBy(100, self::SCALE, self::ROUNDING);

            $withholdingTotal = $withholdingTotal->plus($amount);
            $withholdingResults[] = new WithholdingResult($withholdingBase, $amount);
        }

        // Withholdings do not reduce the payable amount; they are reported apart and lower the amount due.
        $taxInclusive = $lineExtension->plus($taxTotal);

        return new Calculation($lineResults, $taxes, $withholdingResults, new Totals(
            subtotalAmount: $subtotal,
            discountTotal: $discounts,
            lineExtensionAmount: $lineExtension,
            taxExclusiveAmount: $taxExclusive,
            taxTotal: $taxTotal,
            taxInclusiveAmount: $taxInclusive,
            payableAmount: $taxInclusive,
            withholdingTotal: $withholdingTotal,
            amountDue: $taxInclusive->minus($withholdingTotal),
        ));
    }
}
