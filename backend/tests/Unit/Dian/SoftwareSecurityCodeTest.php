<?php

namespace Tests\Unit\Dian;

use App\Modules\Dian\Domain\SoftwareSecurityCode;
use PHPUnit\Framework\TestCase;

/**
 * The software security code of every document (annex 1.9, section 11.8; sts:SoftwareSecurityCode):
 * SHA-384 of the software ID, its PIN and the full number of the document, concatenated. A wrong code
 * is a rejection (FAB27b, CAB27b, DAB27b). The expected values were computed apart from this code,
 * with sha384sum of GNU coreutils: printf '%s' '<software ID><PIN><number>' | sha384sum. The software
 * ID is the example of the DIAN settings form and the PIN a synthetic one.
 */
final class SoftwareSecurityCodeTest extends TestCase
{
    private const string SOFTWARE_ID = '56f2ae4e-9812-4fad-9255-08fcfcd5ccb0';

    private const string PIN = '12345';

    public function test_a_known_code(): void
    {
        $this->assertSame(
            'e4f5e14d6adb376a11a8a52d3dfcea101879296296762903b24eeaf2b41a8b108776ed7cd49b78ab2ddb32aab1183b15',
            SoftwareSecurityCode::calculate(self::SOFTWARE_ID, self::PIN, 'SETP990000001'),
        );
    }

    public function test_the_number_and_the_pin_each_change_the_code(): void
    {
        $this->assertSame(
            '37da422de9e8cffaac4951e52b84c22d0cd8b8e2952a71a52353571dc44689f13efde66f666d7a11e8843a50c3d7f7c4',
            SoftwareSecurityCode::calculate(self::SOFTWARE_ID, self::PIN, 'SETP990000002'),
        );
        $this->assertSame(
            'bd4022a359dba79eff9d731f4a681c20f2a19b698f0e655fdbe78bc3c569b71deed32df853324cafb9f2cfb58bc5d648',
            SoftwareSecurityCode::calculate(self::SOFTWARE_ID, '12346', 'SETP990000001'),
        );
    }

    public function test_the_code_is_96_lowercase_hexadecimal_characters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{96}$/', SoftwareSecurityCode::calculate(self::SOFTWARE_ID, self::PIN, 'NC1'));
    }
}
