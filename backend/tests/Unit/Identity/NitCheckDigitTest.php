<?php

namespace Tests\Unit\Identity;

use App\Modules\Identity\Support\NitCheckDigit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Check digit (DV) of a NIT, modulo 11 with the DIAN weights. A wrong DV refuses a valid company
 * at registration, or lets a typo reach the XML.
 */
final class NitCheckDigitTest extends TestCase
{
    /**
     * Known pairs: the NIT of the DIAN itself, and synthetic NITs worked out by hand, among them the
     * two special remainders (0 gives 0 and 1 gives 1, not 11 minus the remainder).
     *
     * @return iterable<string, array{string, int}>
     */
    public static function knownPairs(): iterable
    {
        yield 'the DIAN, 800.197.268-4' => ['800197268', 4];
        yield 'synthetic 900.123.456-8' => ['900123456', 8];
        yield 'remainder 0 gives 0' => ['15', 0];
        yield 'remainder 1 gives 1' => ['4', 1];
        yield 'one digit' => ['1', 8];
        yield 'leading zeros count as digits' => ['0800197268', 4];
    }

    #[DataProvider('knownPairs')]
    public function test_known_check_digits(string $nit, int $checkDigit): void
    {
        $this->assertSame($checkDigit, NitCheckDigit::calculate($nit));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNits(): iterable
    {
        yield 'empty' => [''];
        yield 'with the dash and DV' => ['800197268-4'];
        yield 'with dots' => ['800.197.268'];
        yield 'sixteen digits' => ['1234567890123456'];
    }

    #[DataProvider('invalidNits')]
    public function test_a_nit_must_be_1_to_15_digits(string $nit): void
    {
        $this->expectException(InvalidArgumentException::class);

        NitCheckDigit::calculate($nit);
    }
}
