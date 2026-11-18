<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Units of measure (UN/ECE codes in the DIAN list, annex 1.9 table 13.3.6). Month (MON) and year
 * (ANN) are not in that list, so they are not offered.
 */
enum UnitOfMeasure: string
{
    use CodeList;

    case Unit = '94';
    case Kilogram = 'KGM';
    case Gram = 'GRM';
    case Liter = 'LTR';
    case Milliliter = 'MLT';
    case Meter = 'MTR';
    case SquareMeter = 'MTK';
    case Hour = 'HUR';
    case Day = 'DAY';
    case Box = 'BX';
    case Pack = 'PK';
    case Dozen = 'DZN';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Kilogram => 'Kilogram',
            self::Gram => 'Gram',
            self::Liter => 'Liter',
            self::Milliliter => 'Milliliter',
            self::Meter => 'Meter',
            self::SquareMeter => 'Square meter',
            self::Hour => 'Hour',
            self::Day => 'Day',
            self::Box => 'Box',
            self::Pack => 'Pack',
            self::Dozen => 'Dozen',
        };
    }
}
