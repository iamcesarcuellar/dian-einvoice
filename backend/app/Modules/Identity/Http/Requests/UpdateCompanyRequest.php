<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Throws with the policy's status and message when the user may not edit the company.
        Gate::authorize('update', $this->company());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CompanyRules::rules($this->company());
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

    private function company(): Company
    {
        return $this->route('company');
    }
}
