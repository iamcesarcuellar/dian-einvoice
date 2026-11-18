<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Rules\DecimalString;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates (POST) or updates (PATCH, partial) a tax or withholding rate. Rates are percentages sent
 * as strings with up to 4 decimals. Company defaults can only be activated or deactivated.
 */
abstract class RateRequest extends FormRequest
{
    /** Table of the rates. */
    abstract protected function table(): string;

    /**
     * @return class-string<BackedEnum>
     */
    abstract protected function codes(): string;

    /** The rate being updated, or null when creating one. */
    abstract protected function record(): ?Model;

    /** Plural name used in messages, such as "Default taxes". */
    abstract protected function defaultsName(): string;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $record = $this->record();

        if ($record?->getAttribute('is_system')) {
            return [
                'dian_code' => ['prohibited'],
                'name' => ['prohibited'],
                'rate' => ['prohibited'],
                'is_active' => ['required', 'boolean'],
            ];
        }

        $required = $record === null ? ['required'] : ['sometimes', 'required'];

        return [
            'dian_code' => [...$required, Rule::enum($this->codes())],
            'name' => [
                ...$required,
                'string',
                'max:100',
                Rule::unique($this->table(), 'name')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($record?->getKey()),
            ],
            'rate' => [...$required, new DecimalString(3, 4, max: '100')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['dian_code' => 'type'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $onlyToggle = $this->defaultsName().' can only be activated or deactivated.';

        return [
            'dian_code.prohibited' => $onlyToggle,
            'name.prohibited' => $onlyToggle,
            'rate.prohibited' => $onlyToggle,
            'name.unique' => 'Another rate already uses this name.',
        ];
    }
}
