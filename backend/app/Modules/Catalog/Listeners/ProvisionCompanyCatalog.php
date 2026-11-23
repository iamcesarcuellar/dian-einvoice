<?php

namespace App\Modules\Catalog\Listeners;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Identity\Events\CompanyCreated;
use App\Modules\Reference\Enums\FiscalResponsibility;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use App\Modules\Reference\Enums\TaxType;
use App\Modules\Reference\Enums\WithholdingType;

/**
 * Every new company starts with the final consumer and the default taxes and withholdings.
 * The defaults are common rates; they can be deactivated but not deleted.
 */
final class ProvisionCompanyCatalog
{
    /** @var list<array{TaxType, string, string}> */
    private const array TAXES = [
        [TaxType::Iva, 'IVA 19 %', '19'],
        [TaxType::Iva, 'IVA 5 %', '5'],
        [TaxType::Iva, 'IVA 0 % (exempt)', '0'],
        [TaxType::Inc, 'INC 8 %', '8'],
    ];

    /** @var list<array{WithholdingType, string, string}> */
    private const array WITHHOLDINGS = [
        [WithholdingType::ReteFuente, 'ReteFuente 2,5 % (purchases)', '2.5'],
        [WithholdingType::ReteFuente, 'ReteFuente 4 % (services)', '4'],
        [WithholdingType::ReteFuente, 'ReteFuente 11 % (fees)', '11'],
        [WithholdingType::ReteIva, 'ReteIVA 15 %', '15'],
    ];

    public function handle(CompanyCreated $event): void
    {
        // No current company exists yet, so every record names its company explicitly.
        $companyId = $event->company->getKey();

        Contact::forceCreate([
            'company_id' => $companyId,
            'organization_type' => OrganizationType::NaturalPerson,
            'identification_type' => IdentificationType::CitizenshipCard,
            'identification_number' => Contact::FINAL_CONSUMER_NUMBER,
            'legal_name' => 'Consumidor final',
            'tax_scheme' => TaxScheme::NotApplicable,
            'fiscal_responsibilities' => [FiscalResponsibility::NotApplicable->value],
            'country_code' => Contact::COLOMBIA,
            'is_system' => true,
        ]);

        foreach (self::TAXES as [$type, $name, $rate]) {
            Tax::forceCreate(['company_id' => $companyId, 'dian_code' => $type, 'name' => $name, 'rate' => $rate, 'is_system' => true]);
        }

        foreach (self::WITHHOLDINGS as [$type, $name, $rate]) {
            Withholding::forceCreate(['company_id' => $companyId, 'dian_code' => $type, 'name' => $name, 'rate' => $rate, 'is_system' => true]);
        }
    }
}
