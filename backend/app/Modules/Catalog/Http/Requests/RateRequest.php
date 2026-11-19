<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\WithholdingType;
use App\Rules\DecimalString;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Creates (POST) or updates (PATCH, partial) a tax or withholding rate. Rates are percentages sent
 * as strings with up to 4 decimals; a ReteICA rate takes up to 3, since the DIAN writes it per
 * thousand with two decimals (table 13.2.2). Company defaults are common values offered as a
 * starting point: their name, rate and status can be changed, their type cannot, and they cannot
 * be deleted. Issued documents keep the rates they were issued with.
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
        $required = $record === null ? ['required'] : ['sometimes', 'required'];
        $code = $this->input('dian_code', $record?->getAttribute('dian_code') instanceof BackedEnum ? $record->getAttribute('dian_code')->value : $record?->getAttribute('dian_code'));
        $scale = $code === WithholdingType::ReteIca->value && $this->codes() === WithholdingType::class ? 3 : 4;

        return [
            'dian_code' => $record?->getAttribute('is_system') ? ['prohibited'] : [...$required, Rule::enum($this->codes())],
            'name' => [
                ...$required,
                'string',
                'max:100',
                Rule::unique($this->table(), 'name')
                    ->where('company_id', app(CurrentCompany::class)->id())
                    ->ignore($record?->getKey()),
            ],
            'rate' => [...$required, new DecimalString(3, $scale, max: '100')],
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
        return [
            'dian_code.prohibited' => 'The type of one of the '.Str::lower($this->defaultsName()).' cannot be changed.',
            'name.unique' => 'Another rate already uses this name.',
        ];
    }
}
