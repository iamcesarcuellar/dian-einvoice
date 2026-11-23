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
 * Every new company starts with the final consumer and the default taxes and withholdings, named in the language of
 * its documents (catalog::defaults). The defaults are common rates; they can be deactivated but not deleted.
 */
final class ProvisionCompanyCatalog
{
    /** @var list<array{TaxType, string, string}> Type, the key of the name in catalog::defaults.taxes, and rate. */
    private const array TAXES = [
        [TaxType::Iva, 'iva_19', '19'],
        [TaxType::Iva, 'iva_5', '5'],
        [TaxType::Iva, 'iva_0', '0'],
        [TaxType::Inc, 'inc_8', '8'],
    ];

    /** @var list<array{WithholdingType, string, string}> Type, the key of the name in catalog::defaults.withholdings, and rate. */
    private const array WITHHOLDINGS = [
        [WithholdingType::ReteFuente, 'rete_fuente_purchases', '2.5'],
        [WithholdingType::ReteFuente, 'rete_fuente_services', '4'],
        [WithholdingType::ReteFuente, 'rete_fuente_fees', '11'],
        [WithholdingType::ReteIva, 'rete_iva', '15'],
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

        $language = $event->company->documentLanguage()->value;

        foreach (self::TAXES as [$type, $name, $rate]) {
            Tax::forceCreate(['company_id' => $companyId, 'dian_code' => $type, 'name' => self::name("taxes.{$name}", $language), 'rate' => $rate, 'is_system' => true]);
        }

        foreach (self::WITHHOLDINGS as [$type, $name, $rate]) {
            Withholding::forceCreate(['company_id' => $companyId, 'dian_code' => $type, 'name' => self::name("withholdings.{$name}", $language), 'rate' => $rate, 'is_system' => true]);
        }
    }

    /** The name of a default tax or withholding, such as "taxes.iva_0", in the language of the company's documents. */
    public static function name(string $key, ?string $language = null): string
    {
        return (string) __("catalog::defaults.{$key}", [], $language);
    }
}
