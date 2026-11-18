<?php

namespace App\Modules\Dian\Domain;

/**
 * CUDE, the unique code of a credit or debit note (annex 1.9, section 11.4): SHA-384, in lowercase
 * hexadecimal, of NumDoc + FecDoc + HorDoc + ValDoc + 01 + ValImp1 + 04 + ValImp2 + 03 + ValImp3 +
 * ValTot + NitOFE + NumAdq + Software-PIN + TipoAmbiente, concatenated without separators, with the
 * amounts written as the CUFE writes them. Pure PHP and brick/math: no framework, no database.
 */
final class Cude
{
    public const string ALGORITHM = 'CUDE-SHA384';

    private const string IVA = '01';

    private const string INC = '04';

    private const string ICA = '03';

    public static function calculate(CudeInput $input): string
    {
        return hash('sha384', self::composition($input));
    }

    /** The string that is hashed, useful to compare with the annex examples. */
    public static function composition(CudeInput $input): string
    {
        return $input->number
            .$input->issueDate
            .$input->issueTime
            .Cufe::amount($input->lineExtensionAmount)
            .self::IVA.Cufe::amount($input->ivaAmount)
            .self::INC.Cufe::amount($input->incAmount)
            .self::ICA.Cufe::amount($input->icaAmount)
            .Cufe::amount($input->payableAmount)
            .$input->issuerNit
            .$input->customerId
            .$input->softwarePin
            .$input->environment;
    }
}
