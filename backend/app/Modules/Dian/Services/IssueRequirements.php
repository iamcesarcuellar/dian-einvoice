<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Identity\Models\Company;

/**
 * What an electronic invoice needs before it can be issued, beyond the draft itself: the tax
 * profile of the company, its DIAN software, the technical key of the numbering and the
 * identification of the customer. Error keys start with the screen where each item is completed
 * (company., dian., numbering., customer.), so clients can link to it.
 */
final class IssueRequirements
{
    /** Company fields, with the message shown when one is missing. */
    private const array COMPANY = [
        'legal_name' => 'Add the legal name of the company.',
        'identification_number' => 'Add the NIT of the company.',
        'organization_type' => 'Choose the organization type of the company.',
        'tax_scheme' => 'Choose the tax scheme of the company.',
        'fiscal_responsibilities' => 'Choose the fiscal responsibilities of the company.',
        'address' => 'Add the address of the company.',
        'municipality_code' => 'Choose the municipality of the company.',
        'email' => 'Add the billing email of the company.',
    ];

    /**
     * @return array<string, list<string>> Messages by key; empty when nothing is missing.
     */
    public function missing(Company $company, DianSetting $settings, ?NumberTemplate $numbering, ?Contact $customer): array
    {
        $errors = [];

        foreach (self::COMPANY as $field => $message) {
            if (blank($company->getAttribute($field))) {
                $errors["company.{$field}"][] = $message;
            }
        }

        if (blank($settings->software_id)) {
            $errors['dian.software_id'][] = 'Add the software ID in the DIAN settings.';
        }

        if (blank($settings->software_pin)) {
            $errors['dian.software_pin'][] = 'Add the software PIN in the DIAN settings.';
        }

        if ($numbering !== null && blank($numbering->technical_key)) {
            $errors['numbering.technical_key'][] = sprintf(
                'Add the technical key of the numbering %s.',
                trim(($numbering->prefix ?? '').' '.$numbering->from_number.' to '.$numbering->to_number),
            );
        }

        if ($customer !== null && (blank($customer->identification_type) || blank($customer->identification_number))) {
            $errors['customer.identification_number'][] = 'Add the identification of the customer.';
        }

        return $errors;
    }
}
