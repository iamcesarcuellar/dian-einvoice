<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Tax types charged on documents (annex 1.9, table 13.2.2 of the toolbox): IVA and INC, the two
 * this product models.
 */
enum TaxType: string
{
    use CodeList;

    case Iva = '01';
    case Inc = '04';

    public function label(): string
    {
        return match ($this) {
            self::Iva => 'IVA',
            self::Inc => 'INC',
        };
    }
}
