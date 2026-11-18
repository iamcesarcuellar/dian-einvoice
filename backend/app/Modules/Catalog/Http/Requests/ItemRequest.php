<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ItemType;
use App\Modules\Catalog\Models\Item;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\UnitOfMeasure;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creates (POST) or updates (PATCH, partial) an item. Prices travel as strings with up to 6 decimals.
 */
final class ItemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $item = $this->item();
        $required = $item === null ? ['required'] : ['sometimes', 'required'];
        $companyId = app(CurrentCompany::class)->id();

        return [
            'code' => [
                ...$required,
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9._\/-]+$/',
                Rule::unique('items', 'code')->where('company_id', $companyId)->ignore($item?->getKey()),
            ],
            'name' => [...$required, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => [...$required, Rule::enum(ItemType::class)],
            'unit_code' => [...$required, Rule::enum(UnitOfMeasure::class)],
            'unit_price' => [...$required, new DecimalString(12, 6)],
            // Empty means excluded: the item carries no tax.
            'tax_id' => ['nullable', 'string', Rule::exists('taxes', 'id')->where('company_id', $companyId)],
            'standard_scheme' => ['nullable', 'string', 'max:20', 'required_with:standard_code'],
            'standard_code' => ['nullable', 'string', 'max:50', 'required_with:standard_scheme'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'unit_code' => 'unit',
            'unit_price' => 'unit price',
            'tax_id' => 'tax',
            'standard_scheme' => 'standard',
            'standard_code' => 'standard code',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use letters, digits, dots, dashes, slashes or underscores in the code.',
            'code.unique' => 'Another item already uses this code.',
        ];
    }

    private function item(): ?Item
    {
        return $this->route('item');
    }
}
