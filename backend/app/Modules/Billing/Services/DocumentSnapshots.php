<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Identity\Models\Company;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Models\Municipality;

/**
 * The copies frozen on a document when it is issued, so later changes to the company, the
 * customer or the numbering do not alter it.
 *
 * @phpstan-import-type PlaceSnapshot from Document
 * @phpstan-import-type CompanySnapshot from Document
 * @phpstan-import-type CustomerSnapshot from Document
 * @phpstan-import-type NumberingSnapshot from Document
 */
final class DocumentSnapshots
{
    /**
     * @return CompanySnapshot
     */
    public function company(Company $company): array
    {
        $company->loadMissing('municipality.department');

        return [
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'identification_type' => IdentificationType::Nit->value,
            'identification_number' => $company->identification_number,
            'check_digit' => $company->check_digit,
            'organization_type' => $company->organization_type?->value,
            'tax_scheme' => $company->tax_scheme?->value,
            'fiscal_responsibilities' => $company->fiscal_responsibilities ?? [],
            'registration_number' => $company->registration_number,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'country_code' => Company::COUNTRY_CODE,
            'municipality' => $this->municipality($company->municipality),
            'postal_code' => $company->postal_code,
        ];
    }

    /**
     * @return CustomerSnapshot
     */
    public function customer(Contact $contact): array
    {
        $contact->loadMissing('municipality.department');

        return [
            'id' => $contact->id,
            'organization_type' => $contact->organization_type->value,
            'identification_type' => $contact->identification_type->value,
            'identification_number' => $contact->identification_number,
            'check_digit' => $contact->check_digit,
            'legal_name' => $contact->legal_name,
            'trade_name' => $contact->trade_name,
            'tax_scheme' => $contact->tax_scheme->value,
            'fiscal_responsibilities' => $contact->fiscal_responsibilities,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'address' => $contact->address,
            'country_code' => $contact->country_code,
            'municipality' => $this->municipality($contact->municipality),
            'postal_code' => $contact->postal_code,
        ];
    }

    /**
     * Resolution data of the numbering: number and date of the resolution, prefix, range and
     * validity. The technical key is not copied: it stays encrypted.
     *
     * @return NumberingSnapshot
     */
    public function numbering(NumberTemplate $template): array
    {
        return [
            'number_template_id' => $template->id,
            'prefix' => $template->prefix,
            'from_number' => $template->from_number,
            'to_number' => $template->to_number,
            'resolution_number' => $template->resolution_number,
            'resolution_date' => $template->resolution_date?->toDateString(),
            'valid_from' => $template->valid_from?->toDateString(),
            'valid_to' => $template->valid_to?->toDateString(),
        ];
    }

    /**
     * @return PlaceSnapshot|null
     */
    private function municipality(?Municipality $municipality): ?array
    {
        if ($municipality === null) {
            return null;
        }

        return [
            'code' => $municipality->code,
            'name' => $municipality->name,
            'department_code' => $municipality->department_code,
            'department_name' => $municipality->department?->name,
        ];
    }
}
