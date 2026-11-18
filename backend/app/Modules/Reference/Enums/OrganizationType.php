<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Organization types (DIAN technical annex 1.9).
 */
enum OrganizationType: string
{
    use CodeList;

    case LegalEntity = '1';
    case NaturalPerson = '2';

    public function label(): string
    {
        return match ($this) {
            self::LegalEntity => 'Legal entity',
            self::NaturalPerson => 'Natural person',
        };
    }
}
