<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Dian\Enums\CertificateStatus;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Models\SigningCertificate;
use App\Modules\Identity\Models\Company;

/**
 * What an electronic invoice needs before it can be issued, beyond the draft itself: the tax
 * profile of the company, its DIAN software, a signing certificate valid today, the technical key
 * of the numbering and the identification of the customer. Error keys start with the screen where
 * each item is completed (company., dian., numbering., customer.), so clients can link to it.
 */
final class IssueRequirements
{
    /** Company fields, each with its message when it is missing (dian::errors.requirements.company). */
    private const array COMPANY = ['legal_name', 'identification_number', 'organization_type', 'tax_scheme', 'fiscal_responsibilities', 'address', 'municipality_code', 'email'];

    /**
     * @return array<string, list<string>> Messages by key; empty when nothing is missing.
     */
    public function missing(Company $company, DianSetting $settings, ?SigningCertificate $certificate, ?NumberTemplate $numbering, ?Contact $customer): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        foreach (self::COMPANY as $field) {
            if (blank($company->getAttribute($field))) {
                $errors["company.{$field}"][] = __("dian::errors.requirements.company.{$field}");
            }
        }

        if (blank($settings->software_id)) {
            $errors['dian.software_id'][] = __('dian::errors.requirements.software_id');
        }

        if (blank($settings->software_pin)) {
            $errors['dian.software_pin'][] = __('dian::errors.requirements.software_pin');
        }

        $certificateProblem = $this->certificateProblem($certificate);

        if ($certificateProblem !== null) {
            $errors['dian.certificate'][] = $certificateProblem;
        }

        if ($numbering !== null && blank($numbering->technical_key)) {
            $errors['numbering.technical_key'][] = __('dian::errors.requirements.technical_key', ['numbering' => $this->range($numbering)]);
        }

        // The graphic representation prints the date of the resolution in its authorization legend.
        if ($numbering !== null && $numbering->resolution_date === null) {
            $errors['numbering.resolution_date'][] = __('dian::errors.requirements.resolution_date', ['numbering' => $this->range($numbering)]);
        }

        if ($customer !== null && (blank($customer->identification_type) || blank($customer->identification_number))) {
            $errors['customer.identification_number'][] = __('dian::errors.requirements.customer_identification');
        }

        return $errors;
    }

    /** Why the certificate cannot sign today, or null when it can. */
    private function certificateProblem(?SigningCertificate $certificate): ?string
    {
        if ($certificate === null) {
            return (string) __('dian::errors.requirements.certificate');
        }

        return match ($certificate->status()) {
            CertificateStatus::Expired => (string) __('dian::errors.requirements.certificate_expired', ['date' => $certificate->valid_to->format(__('formats.date'))]),
            CertificateStatus::NotYetValid => (string) __('dian::errors.requirements.certificate_upcoming', ['date' => $certificate->valid_from->format(__('formats.date'))]),
            CertificateStatus::Valid, CertificateStatus::ExpiringSoon => null,
        };
    }

    /** A numbering range as the messages name it: "SETP 1 to 5000" in English. */
    private function range(NumberTemplate $numbering): string
    {
        return trim(__('billing::errors.numbering.range', ['prefix' => $numbering->prefix ?? '', 'from' => $numbering->from_number, 'to' => $numbering->to_number]));
    }
}
