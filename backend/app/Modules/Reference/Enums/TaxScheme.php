<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Tax scheme of a party (DIAN technical annex 1.9).
 */
enum TaxScheme: string
{
    use CodeList;

    case Iva = '01';
    case Inc = '04';
    case IvaAndInc = 'ZA';
    case NotApplicable = 'ZZ';

    public function label(): string
    {
        return match ($this) {
            self::Iva => 'IVA',
            self::Inc => 'INC',
            self::IvaAndInc => 'IVA and INC',
            self::NotApplicable => 'Not applicable',
        };
    }
}
