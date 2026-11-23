<?php

namespace App\Modules\Reference\Enums;

use App\Modules\Reference\Enums\Concerns\CodeList;

/**
 * Identity document types (annex 1.9, table 13.2.1 of the toolbox, cbc:CompanyID/@schemeName).
 * The issuer is always identified by NIT (31, rule FAJ25); every code in the table is valid for
 * the customer, PEP (47) and PPT (48) included, and NUIP (91) only for the customer, as the
 * table notes. The toolbox's code-list schematron (listacodigos v1.6, 2019) predates 10, 47 and
 * 48; annex 1.9 added 48.
 */
enum IdentificationType: string
{
    use CodeList;

    case BirthCertificate = '10';
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
            self::BirthCertificate => 'Birth certificate',
            self::CivilRegistration => 'Civil registration',
            self::IdentityCard => 'Identity card',
            self::CitizenshipCard => 'Citizenship card',
            self::ForeignerCard => 'Foreigner card',
            self::ForeignerId => 'Foreigner ID card',
            self::Nit => 'NIT',
            self::Passport => 'Passport',
            self::ForeignId => 'Foreign identification document',
            self::Pep => 'Special stay permit (PEP)',
            self::Ppt => 'Temporary protection permit (PPT)',
            self::ForeignNit => 'Foreign NIT',
            self::Nuip => 'NUIP',
        };
    }
}
