<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Rules\MatchesNitCheckDigit;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use App\Modules\Reference\Rules\ValidFiscalResponsibilities;
use App\Support\Language;
use App\Support\Translation;
use Illuminate\Validation\Rule;

/**
 * Company validation shared by registration, company creation and company updates.
 */
final class CompanyRules
{
    /** Company fields accepted from clients. */
    public const array FIELDS = [
        'name',
        'legal_name',
        'identification_number',
        'check_digit',
        'organization_type',
        'tax_scheme',
        'fiscal_responsibilities',
        'registration_number',
        'email',
        'phone',
        'address',
        'municipality_code',
        'postal_code',
        'document_language',
    ];

    /**
     * Only the name and the NIT are required; the tax profile can be completed later in settings.
     *
     * @param  Company|null  $company  The company being updated, or null when creating one.
     * @param  string  $prefix  Key prefix when the company is nested, such as "company.".
     * @param  bool  $unique  Whether a NIT already registered fails validation on its field. Sign-up
     *                        checks it itself, with an answer that does not tell which field it was.
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?Company $company = null, string $prefix = '', bool $unique = true): array
    {
        $required = $company === null ? ['required'] : ['sometimes', 'required'];

        return [
            $prefix.'name' => [...$required, 'string', 'max:120'],
            $prefix.'legal_name' => ['nullable', 'string', 'max:200'],
            $prefix.'identification_number' => [
                ...$required,
                'string',
                'regex:/^\d{5,15}$/',
                ...($unique ? [Rule::unique('companies', 'identification_number')->ignore($company?->getKey())] : []),
            ],
            $prefix.'check_digit' => [
                'nullable',
                'integer',
                'between:0,9',
                new MatchesNitCheckDigit($prefix.'identification_number', $company?->identification_number),
            ],
            $prefix.'organization_type' => ['nullable', Rule::enum(OrganizationType::class)],
            $prefix.'tax_scheme' => ['nullable', Rule::enum(TaxScheme::class)],
            $prefix.'fiscal_responsibilities' => ['nullable', new ValidFiscalResponsibilities],
            $prefix.'registration_number' => ['nullable', 'string', 'max:20'],
            $prefix.'email' => ['nullable', 'string', 'email', 'max:255'],
            $prefix.'phone' => ['nullable', 'string', 'max:30'],
            $prefix.'address' => ['nullable', 'string', 'max:200'],
            $prefix.'municipality_code' => ['nullable', 'string', Rule::exists('municipalities', 'code')],
            $prefix.'postal_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            // The language of its documents; a new company takes the language of the request that creates it.
            $prefix.'document_language' => ['sometimes', Rule::enum(Language::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(string $prefix = ''): array
    {
        return Translation::lines('identity::validation.attributes.company', $prefix);
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        return Translation::lines('identity::validation.messages.company', $prefix);
    }

    /**
     * Accepts NITs typed with dots or spaces, such as "900.123.456".
     */
    public static function normalizeNit(mixed $value): mixed
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) ? preg_replace('/[\s.]+/', '', $value) : $value;
    }
}
