<?php

namespace Tests\Unit\Billing;

use App\Modules\Billing\Domain\Calculation;
use App\Modules\Billing\Domain\DocumentCalculator;
use App\Modules\Billing\Domain\LineInput;
use App\Modules\Billing\Domain\WithholdingInput;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The only place where document arithmetic happens: every amount the API stores, the XML carries
 * and the DIAN rules FAU02, FAU04, FAU06 and FAU14 check comes from here.
 */
final class DocumentCalculatorTest extends TestCase
{
    private const string IVA = '01';

    private const string INC = '04';

    public function test_a_line_is_quantity_times_price_less_its_discount(): void
    {
        $result = $this->calculate([new LineInput('3', '12500.50', '10', self::IVA, '19')]);
        $line = $result->lines[0];

        $this->assertSame('37501.50', (string) $line->baseAmount);
        $this->assertSame('3750.15', (string) $line->discountAmount);
        $this->assertSame('33751.35', (string) $line->lineExtensionAmount);
        // 33,751.35 x 19 % = 6,412.7565
        $this->assertSame('6412.76', (string) $line->taxAmount);
        $this->assertSame('37501.50', (string) $result->totals->subtotalAmount);
        $this->assertSame('3750.15', (string) $result->totals->discountTotal);
    }

    /**
     * Half to even (annex 1.9, 5.2.1): a 5 in the third decimal goes to the even cent.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function halfEvenCases(): iterable
    {
        yield 'base 0.125 down to the even 0.12' => ['1', '0.125', '0.12'];
        yield 'base 0.135 up to the even 0.14' => ['1', '0.135', '0.14'];
        yield 'base 0.145 down to the even 0.14' => ['1', '0.145', '0.14'];
        yield 'base 3 x 0.125 = 0.375 up to 0.38' => ['3', '0.125', '0.38'];
        yield 'quantity with six decimals' => ['0.333333', '3', '1.00'];
    }

    #[DataProvider('halfEvenCases')]
    public function test_the_line_base_rounds_half_to_even(string $quantity, string $price, string $base): void
    {
        $this->assertSame($base, (string) $this->calculate([new LineInput($quantity, $price)])->lines[0]->baseAmount);
    }

    public function test_taxes_and_discounts_round_half_to_even_too(): void
    {
        $result = $this->calculate([
            // 1.50 x 19 % = 0.285: the 8 is even, it stays.
            new LineInput('1', '1.50', '0', self::IVA, '19'),
            // 0.50 x 19 % = 0.095: the 9 is odd, it goes up.
            new LineInput('1', '0.50', '0', self::IVA, '19'),
            // 1.50 x 5 % = 0.075 twice: the 7 is odd, each goes up to 0.08.
            new LineInput('1', '1.50', '0', self::IVA, '5'),
            new LineInput('1', '1.50', '0', self::IVA, '5'),
            // 33.33 x 15 % = 4.9995 of discount.
            new LineInput('1', '33.33', '15'),
        ]);

        $this->assertSame(['0.28', '0.10', '0.08', '0.08'], array_map(fn ($line): string => (string) $line->taxAmount, array_slice($result->lines, 0, 4)));
        $this->assertSame('5.00', (string) $result->lines[4]->discountAmount);
        $this->assertSame('28.33', (string) $result->lines[4]->lineExtensionAmount);
        // A group is the sum of its rounded line taxes: 0.16 for the 5 %, never 3.00 x 5 % = 0.15.
        $this->assertSame(['0.38', '0.16'], [(string) $result->taxes[0]->taxAmount, (string) $result->taxes[1]->taxAmount]);
    }

    public function test_taxes_are_grouped_by_code_and_rate_with_the_highest_rate_first(): void
    {
        $result = $this->calculate([
            new LineInput('1', '1000', '0', self::IVA, '5'),
            new LineInput('2', '1000', '0', self::INC, '8'),
            new LineInput('1', '3000', '0', self::IVA, '19'),
            new LineInput('1', '500', '0', self::IVA, '19.00'),
        ]);

        $groups = array_map(fn ($tax): array => [$tax->code, (string) $tax->rate, (string) $tax->taxableAmount, (string) $tax->taxAmount], $result->taxes);

        $this->assertSame([
            [self::IVA, '19.0000', '3500.00', '665.00'],
            [self::IVA, '5.0000', '1000.00', '50.00'],
            [self::INC, '8.0000', '2000.00', '160.00'],
        ], $groups);
    }

    public function test_an_excluded_line_carries_no_tax_while_an_exempt_one_is_taxed_at_zero(): void
    {
        $result = $this->calculate([
            new LineInput('1', '1000'),
            new LineInput('1', '2000', '0', self::IVA, '0'),
        ]);

        $this->assertSame('3000.00', (string) $result->totals->lineExtensionAmount);
        // Only the exempt line is part of the taxable base (FAU04).
        $this->assertSame('2000.00', (string) $result->totals->taxExclusiveAmount);
        $this->assertSame('0.00', (string) $result->totals->taxTotal);
        $this->assertCount(1, $result->taxes);
        $this->assertSame('2000.00', (string) $result->taxes[0]->taxableAmount);
    }

    public function test_each_withholding_takes_its_own_base(): void
    {
        $result = $this->calculate(
            [
                new LineInput('1', '1000000', '0', self::IVA, '19'),
                new LineInput('1', '200000', '0', self::INC, '8'),
            ],
            [
                // ReteFuente 2.5 % on the line values.
                new WithholdingInput('2.5', false),
                // ReteIVA 15 % on the IVA only, never on the INC.
                new WithholdingInput('15', true),
                // ReteICA of 9.66 per thousand, stored as 0.966 %.
                new WithholdingInput('0.966', false),
            ],
        );

        [$retefuente, $reteiva, $reteica] = $result->withholdings;

        $this->assertSame(['1200000.00', '30000.00'], [(string) $retefuente->baseAmount, (string) $retefuente->amount]);
        $this->assertSame(['190000.00', '28500.00'], [(string) $reteiva->baseAmount, (string) $reteiva->amount]);
        $this->assertSame(['1200000.00', '11592.00'], [(string) $reteica->baseAmount, (string) $reteica->amount]);
        $this->assertSame('70092.00', (string) $result->totals->withholdingTotal);
    }

    public function test_reteica_per_thousand_rounds_half_to_even(): void
    {
        // 123,457 x 9.66 per thousand = 1,192.59462
        $result = $this->calculate([new LineInput('1', '123457')], [new WithholdingInput('0.966', false)]);

        $this->assertSame('1192.59', (string) $result->withholdings[0]->amount);
    }

    public function test_withholdings_lower_the_amount_due_but_not_the_payable_amount(): void
    {
        $totals = $this->calculate([new LineInput('1', '1000000', '0', self::IVA, '19')], [new WithholdingInput('2.5', false)])->totals;

        $this->assertSame('1190000.00', (string) $totals->taxInclusiveAmount);
        $this->assertSame('1190000.00', (string) $totals->payableAmount);
        $this->assertSame('25000.00', (string) $totals->withholdingTotal);
        $this->assertSame('1165000.00', (string) $totals->amountDue);
    }

    /**
     * Header taxes are the sum of the line taxes, the total is the lines plus the taxes, and the
     * net is the total less the withholdings, exactly, for any mix of lines (a fixed seed keeps
     * the run reproducible).
     */
    public function test_the_identities_of_the_totals_always_hold(): void
    {
        mt_srand(20261119);
        $rates = [[self::IVA, '19'], [self::IVA, '5'], [self::IVA, '0'], [self::INC, '8'], [null, null]];

        for ($document = 0; $document < 300; $document++) {
            $lines = [];

            for ($i = 0, $count = mt_rand(1, 25); $i < $count; $i++) {
                [$code, $rate] = $rates[mt_rand(0, count($rates) - 1)];
                $lines[] = new LineInput(
                    sprintf('%d.%03d', mt_rand(0, 40), mt_rand(1, 999)),
                    sprintf('%d.%02d', mt_rand(0, 2_000_000), mt_rand(0, 99)),
                    (string) [0, 0, 5, 12.5, 33.3333][mt_rand(0, 4)],
                    $code,
                    $rate,
                );
            }

            $result = $this->calculate($lines, [new WithholdingInput('2.5', false), new WithholdingInput('15', true), new WithholdingInput('0.966', false)]);
            $totals = $result->totals;

            $lineTaxes = $this->sum(array_map(fn ($line) => $line->taxAmount, $result->lines));
            $groupTaxes = $this->sum(array_map(fn ($tax) => $tax->taxAmount, $result->taxes));
            $lineValues = $this->sum(array_map(fn ($line) => $line->lineExtensionAmount, $result->lines));
            $withholdings = $this->sum(array_map(fn ($withholding) => $withholding->amount, $result->withholdings));

            $this->assertTrue($groupTaxes->isEqualTo($lineTaxes), "Document {$document}: header taxes differ from the line taxes.");
            $this->assertTrue($totals->taxTotal->isEqualTo($lineTaxes), "Document {$document}: tax total differs from the line taxes.");
            $this->assertTrue($totals->lineExtensionAmount->isEqualTo($lineValues), "Document {$document}: line extension differs from the lines.");
            $this->assertTrue($totals->taxInclusiveAmount->isEqualTo($totals->lineExtensionAmount->plus($totals->taxTotal)), "Document {$document}: total is not lines plus taxes.");
            $this->assertTrue($totals->amountDue->isEqualTo($totals->payableAmount->minus($withholdings)), "Document {$document}: net is not total less withholdings.");
            $this->assertTrue($totals->subtotalAmount->minus($totals->discountTotal)->isEqualTo($totals->lineExtensionAmount), "Document {$document}: subtotal less discounts is not the line extension.");
        }
    }

    /**
     * @param  list<LineInput>  $lines
     * @param  list<WithholdingInput>  $withholdings
     */
    private function calculate(array $lines, array $withholdings = []): Calculation
    {
        return (new DocumentCalculator)->calculate($lines, $withholdings);
    }

    /**
     * @param  list<BigDecimal>  $amounts
     */
    private function sum(array $amounts): BigDecimal
    {
        return array_reduce($amounts, fn (BigDecimal $sum, BigDecimal $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero());
    }
}
