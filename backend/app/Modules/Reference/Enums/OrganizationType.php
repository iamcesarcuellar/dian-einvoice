<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Organization types (annex 1.9, table 13.2.3 of the toolbox, cbc:AdditionalAccountID): legal
 * entities and natural persons, each "and similar" in the official names.
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
