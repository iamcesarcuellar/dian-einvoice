<?php

namespace App\Modules\Dian\Domain;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * CUFE, the unique code of an electronic invoice (annex 1.9, section 11.2): SHA-384, in lowercase
 * hexadecimal, of NumFac + FecFac + HorFac + ValFac + 01 + ValImp1 + 04 + ValImp2 + 03 + ValImp3 +
 * ValTot + NitOFE + NumAdq + ClTec + TipoAmbiente, concatenated without separators.
 * Pure PHP and brick/math: no framework, no database.
 */
final class Cufe
{
    public const string ALGORITHM = 'CUFE-SHA384';

    private const string IVA = '01';

    private const string INC = '04';

    private const string ICA = '03';

    public static function calculate(CufeInput $input): string
    {
        return hash('sha384', self::composition($input));
    }

    /** The string that is hashed, useful to compare with the annex examples. */
    public static function composition(CufeInput $input): string
    {
        return $input->number
            .$input->issueDate
            .$input->issueTime
            .self::amount($input->lineExtensionAmount)
            .self::IVA.self::amount($input->ivaAmount)
            .self::INC.self::amount($input->incAmount)
            .self::ICA.self::amount($input->icaAmount)
            .self::amount($input->payableAmount)
            .$input->issuerNit
            .$input->customerId
            .$input->technicalKey
            .$input->environment;
    }

    /**
     * Point as decimal separator, exactly two decimals (truncated, as the annex says) and no
     * thousands separator: "1500000" and "1500000.009" both become "1500000.00".
     */
    public static function amount(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(2, RoundingMode::Down);
    }
}
