<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * A party's tax scheme (annex 1.9, table 13.2.6.2 of the toolbox, PartyTaxScheme/cac:TaxScheme).
 * Annex 1.9 has no separate list of regimes: TaxLevelCode/@listName is optional and, when sent,
 * "No aplica", so it is left out.
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
