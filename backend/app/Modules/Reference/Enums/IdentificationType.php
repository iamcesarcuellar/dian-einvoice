<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Identity document types (DIAN technical annex 1.9).
 */
enum IdentificationType: string
{
    use CodeList;

    case CivilRegistration = '11';
    case IdentityCard = '12';
    case CitizenshipCard = '13';
    case ForeignerCard = '21';
    case ForeignerId = '22';
    case Nit = '31';
    case Passport = '41';
    case ForeignId = '42';
    case Pep = '47';
    case Ppt = '48';
    case ForeignNit = '50';
    case Nuip = '91';

    public function label(): string
    {
        return match ($this) {
            self::CivilRegistration => 'Civil registration',
            self::IdentityCard => 'Identity card',
            self::CitizenshipCard => 'Citizenship card',
            self::ForeignerCard => 'Foreigner card',
            self::ForeignerId => 'Foreigner ID card',
            self::Nit => 'NIT',
            self::Passport => 'Passport',
            self::ForeignId => 'Foreign identification document',
            self::Pep => 'PEP',
            self::Ppt => 'PPT',
            self::ForeignNit => 'Foreign NIT',
            self::Nuip => 'NUIP',
        };
    }
}
