<?php

namespace Tests\Unit\Dian;

use App\Modules\Dian\Domain\Cude;
use App\Modules\Dian\Domain\CudeInput;
use App\Modules\Dian\Domain\Cufe;
use App\Modules\Dian\Domain\CufeInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CUFE and CUDE against the published examples of annex 1.9: a wrong key is a rejection (FAD06,
 * CAD06, DAD06) of every document the platform issues.
 */
final class DocumentKeyTest extends TestCase
{
    public function test_the_cufe_of_the_annex_example_11_2_1(): void
    {
        $input = $this->annexInvoice();

        $this->assertSame(
            '323200000129'.'2019-01-16'.'10:53:10-05:00'.'1500000.00'.'01'.'285000.00'.'04'.'0.00'.'03'.'0.00'.'1785000.00'.'700085371'.'800199436'.'693ff6f2a553c3646a063436fd4dd9ded0311471'.'1',
            Cufe::composition($input),
        );
        $this->assertSame('8bb918b19ba22a694f1da11c643b5e9de39adf60311cf179179e9b33381030bcd4c3c3f156c506ed5908f9276f5bd9b4', Cufe::calculate($input));
    }

    public function test_the_cude_of_the_credit_note_example_11_4_3(): void
    {
        $input = new CudeInput(
            number: '8110007871',
            issueDate: '2019-01-12',
            issueTime: '07:00:00-05:00',
            lineExtensionAmount: '5000.00',
            ivaAmount: '950.00',
            incAmount: '0.00',
            icaAmount: '0.00',
            payableAmount: '5950.00',
            issuerNit: '900373076',
            customerId: '8355990',
            softwarePin: '12301',
            environment: 1,
        );

        $this->assertSame('907e4444decc9e59c160a2fb3b6659b33dc5b632a5008922b9a62f83f757b1c448e47f5867f2b50dbdb96f48c7681168', Cude::calculate($input));
        $this->assertSame('CUDE-SHA384', Cude::ALGORITHM);
        $this->assertSame('CUFE-SHA384', Cufe::ALGORITHM);
    }

    public function test_the_key_is_96_lowercase_hexadecimal_characters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{96}$/', Cufe::calculate($this->annexInvoice()));
    }

    /**
     * Amounts: point as decimal separator, exactly two decimals truncated, no thousands separator.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'an integer gets two decimals' => ['1500000', '1500000.00'];
        yield 'one decimal is padded' => ['1500000.5', '1500000.50'];
        yield 'a third decimal is truncated, not rounded' => ['1500000.009', '1500000.00'];
        yield 'four decimals are truncated' => ['285000.1299', '285000.12'];
        yield 'zero' => ['0', '0.00'];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_written_as_the_annex_asks(string $value, string $written): void
    {
        $this->assertSame($written, Cufe::amount($value));
    }

    public function test_any_value_of_the_composition_changes_the_key(): void
    {
        $base = Cufe::calculate($this->annexInvoice());
        $other = new CufeInput('323200000129', '2019-01-16', '10:53:10-05:00', '1500000.00', '285000.00', '0.00', '0.00', '1785000.01', '700085371', '800199436', '693ff6f2a553c3646a063436fd4dd9ded0311471', 1);
        $test = new CufeInput('323200000129', '2019-01-16', '10:53:10-05:00', '1500000.00', '285000.00', '0.00', '0.00', '1785000.00', '700085371', '800199436', '693ff6f2a553c3646a063436fd4dd9ded0311471', 2);

        $this->assertNotSame($base, Cufe::calculate($other));
        $this->assertNotSame($base, Cufe::calculate($test));
    }

    private function annexInvoice(): CufeInput
    {
        return new CufeInput(
            number: '323200000129',
            issueDate: '2019-01-16',
            issueTime: '10:53:10-05:00',
            lineExtensionAmount: '1500000.00',
            ivaAmount: '285000.00',
            incAmount: '0.00',
            icaAmount: '0.00',
            payableAmount: '1785000.00',
            issuerNit: '700085371',
            customerId: '800199436',
            technicalKey: '693ff6f2a553c3646a063436fd4dd9ded0311471',
            environment: 1,
        );
    }
}
