<?php

namespace App\Modules\Identity\Http\Requests;

use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Whether the email or the NIT is already registered is checked on create, with one
            // answer for both (AuthController::register), so the form tells no field apart.
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
            'company' => ['required', 'array'],
            ...CompanyRules::rules(prefix: 'company.', unique: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...Translation::lines('identity::validation.attributes.register'), ...CompanyRules::attributes('company.')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CompanyRules::messages('company.');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }

        $company = $this->input('company');

        if (is_array($company) && array_key_exists('identification_number', $company)) {
            $company['identification_number'] = CompanyRules::normalizeNit($company['identification_number']);
            $this->merge(['company' => $company]);
        }
    }
}
