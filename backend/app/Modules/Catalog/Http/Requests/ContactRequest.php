<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Identity\Http\Requests\CompanyRules;
use App\Modules\Identity\Rules\MatchesNitCheckDigit;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\TaxScheme;
use App\Modules\Reference\Rules\ValidFiscalResponsibilities;
use App\Support\Translation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Creates (POST) or updates (PATCH, partial) a contact.
 */
final class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->contact()?->is_system) {
            throw new AccessDeniedHttpException(__('catalog::errors.final_consumer_edit'));
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $contact = $this->contact();
        $required = $contact === null ? ['required'] : ['sometimes', 'required'];
        /** @var array<mixed>|bool|float|int|string|null $type Not validated yet: any value of the body. */
        $type = $this->input('identification_type', $contact?->identification_type?->value);
        $isNit = $type === IdentificationType::Nit->value;

        return [
            'organization_type' => [...$required, Rule::enum(OrganizationType::class)],
            'identification_type' => [...$required, Rule::enum(IdentificationType::class)],
            'identification_number' => [
                ...$required,
                'string',
                $isNit ? 'regex:/^\d{5,15}$/' : 'regex:/^[A-Za-z0-9]{3,20}$/',
                Rule::unique('contacts', 'identification_number')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->where('identification_type', $type)
                    ->ignore($contact?->getKey()),
            ],
            // Only for a NIT, and only to confirm it: the API computes the check digit anyway.
            'check_digit' => $isNit
                ? ['nullable', 'integer', 'between:0,9', new MatchesNitCheckDigit('identification_number', $contact?->identification_number)]
                : ['prohibited'],
            'legal_name' => [...$required, 'string', 'max:200'],
            'trade_name' => ['nullable', 'string', 'max:200'],
            'tax_scheme' => [...$required, Rule::enum(TaxScheme::class)],
            'fiscal_responsibilities' => [...$required, new ValidFiscalResponsibilities],
            // Documents are delivered to this address.
            'email' => [...$required, 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:200'],
            'country_code' => ['sometimes', 'required', 'string', 'regex:/^[A-Z]{2}$/'],
            'municipality_code' => ['nullable', 'string', Rule::exists('municipalities', 'code')],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A contact in Colombia needs a municipality; a contact abroad has none.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('municipality_code') || $validator->errors()->has('country_code')) {
                return;
            }

            $contact = $this->contact();
            $country = $this->input('country_code', $contact->country_code ?? Contact::COLOMBIA);
            $municipality = $this->exists('municipality_code') ? $this->input('municipality_code') : $contact?->municipality_code;

            if ($country === Contact::COLOMBIA && blank($municipality)) {
                $validator->errors()->add('municipality_code', __('catalog::validation.municipality_required'));
            }

            if ($country !== Contact::COLOMBIA && filled($this->input('municipality_code'))) {
                $validator->errors()->add('municipality_code', __('catalog::validation.municipality_abroad'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return Translation::lines('catalog::validation.attributes.contact');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Translation::lines('catalog::validation.messages.contact');
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('identification_number')) {
            $merge['identification_number'] = CompanyRules::normalizeNit($this->input('identification_number'));
        }

        if (is_string($this->input('country_code'))) {
            $merge['country_code'] = Str::upper(trim($this->input('country_code')));
        } elseif ($this->contact() === null) {
            $merge['country_code'] = Contact::COLOMBIA;
        }

        $this->merge($merge);
    }

    private function contact(): ?Contact
    {
        return $this->route('contact');
    }
}
