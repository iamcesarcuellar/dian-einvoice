<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCompanyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CompanyRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return CompanyRules::attributes();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CompanyRules::messages();
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('identification_number')) {
            $this->merge(['identification_number' => CompanyRules::normalizeNit($this->input('identification_number'))]);
        }
    }
}
