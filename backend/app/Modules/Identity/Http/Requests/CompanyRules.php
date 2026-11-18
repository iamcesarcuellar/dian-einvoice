<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Rules\MatchesNitCheckDigit;
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
        'email',
        'phone',
        'address',
        'city',
    ];

    /**
     * @param  Company|null  $company  The company being updated, or null when creating one.
     * @param  string  $prefix  Key prefix when the company is nested, such as "company.".
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?Company $company = null, string $prefix = ''): array
    {
        $required = $company === null ? ['required'] : ['sometimes', 'required'];

        return [
            $prefix.'name' => [...$required, 'string', 'max:120'],
            $prefix.'legal_name' => ['nullable', 'string', 'max:200'],
            $prefix.'identification_number' => [
                ...$required,
                'string',
                'regex:/^\d{5,15}$/',
                Rule::unique('companies', 'identification_number')->ignore($company?->getKey()),
            ],
            $prefix.'check_digit' => [
                'nullable',
                'integer',
                'between:0,9',
                new MatchesNitCheckDigit($prefix.'identification_number', $company?->identification_number),
            ],
            $prefix.'email' => ['nullable', 'string', 'email', 'max:255'],
            $prefix.'phone' => ['nullable', 'string', 'max:30'],
            $prefix.'address' => ['nullable', 'string', 'max:200'],
            $prefix.'city' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(string $prefix = ''): array
    {
        return [
            $prefix.'name' => 'company name',
            $prefix.'legal_name' => 'legal name',
            $prefix.'identification_number' => 'NIT',
            $prefix.'check_digit' => 'check digit',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $prefix = ''): array
    {
        return [
            $prefix.'identification_number.regex' => 'The NIT must have between 5 and 15 digits, without the check digit.',
            $prefix.'identification_number.unique' => 'A company with this NIT is already registered.',
        ];
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
