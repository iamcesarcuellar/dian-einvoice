<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\PaymentMeans;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * A payment of one customer and the invoices it is applied to. The amount, when sent, must be the
 * sum applied; whether each amount fits the balance of its invoice is checked with the invoice
 * locked, when the payment is recorded. Decimals travel as strings.
 */
final class RecordPaymentRequest extends FormRequest
{
    private const int MAX_INVOICES = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            'contact_id' => ['required', 'string', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
            'payment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Carbon::now(NumberTemplate::TIMEZONE)->toDateString()],
            'payment_means_code' => ['required', Rule::enum(PaymentMeans::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'amount' => ['nullable', new DecimalString(16, 2)],
            'allocations' => ['required', 'array', 'min:1', 'max:'.self::MAX_INVOICES],
            'allocations.*' => ['array'],
            'allocations.*.document_id' => ['required', 'string', 'distinct'],
            'allocations.*.amount' => ['required', new DecimalString(16, 2)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contact_id' => 'customer',
            'payment_date' => 'date',
            'payment_means_code' => 'payment means',
            'allocations' => 'invoices',
            'allocations.*.document_id' => 'invoice',
            'allocations.*.amount' => 'amount to apply',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_date.before_or_equal' => 'The payment date cannot be later than today.',
        ];
    }
}
