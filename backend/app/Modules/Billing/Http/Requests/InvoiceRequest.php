<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\PaymentForm;
use App\Modules\Reference\Enums\PaymentMeans;
use App\Modules\Reference\Enums\UnitOfMeasure;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of a draft invoice, for create, update and preview. A draft can be incomplete: what is
 * required to issue it is checked when it is issued. Decimals travel as strings.
 */
final class InvoiceRequest extends FormRequest
{
    private const int MAX_LINES = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'contact_id' => ['nullable', 'string', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'number_template_id' => [
                'nullable',
                'string',
                Rule::exists('number_templates', 'id')
                    ->where('company_id', $companyId)
                    ->where('document_type', DocumentType::Invoice->value),
            ],
            'payment_form' => ['nullable', Rule::enum(PaymentForm::class)],
            'payment_means_code' => ['nullable', Rule::enum(PaymentMeans::class)],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['sometimes', 'array', 'max:'.self::MAX_LINES],
            'lines.*' => ['array'],
            'lines.*.item_id' => ['nullable', 'string', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.code' => ['nullable', 'string', 'max:50'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.unit_code' => ['nullable', Rule::enum(UnitOfMeasure::class)],
            'lines.*.quantity' => ['nullable', new DecimalString(12, 6)],
            'lines.*.unit_price' => ['nullable', new DecimalString(12, 6)],
            'lines.*.discount_rate' => ['nullable', new DecimalString(3, 4, max: '100')],
            // Null means excluded: the line carries no tax.
            'lines.*.tax_id' => ['nullable', 'string', Rule::exists('taxes', 'id')->where('company_id', $companyId)],
            'withholding_ids' => ['sometimes', 'array'],
            'withholding_ids.*' => ['string', 'distinct', Rule::exists('withholdings', 'id')->where('company_id', $companyId)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contact_id' => 'customer',
            'number_template_id' => 'numbering',
            'payment_form' => 'payment form',
            'payment_means_code' => 'payment means',
            'lines.*.item_id' => 'item',
            'lines.*.description' => 'description',
            'lines.*.unit_code' => 'unit',
            'lines.*.quantity' => 'quantity',
            'lines.*.unit_price' => 'unit price',
            'lines.*.discount_rate' => 'discount',
            'lines.*.tax_id' => 'tax',
            'withholding_ids.*' => 'withholding',
        ];
    }
}
