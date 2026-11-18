<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Tax types charged on documents (DIAN technical annex 1.9).
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
