<?php

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\CreditNoteConcept;
use App\Modules\Reference\Enums\DebitNoteConcept;
use App\Modules\Reference\Enums\PaymentForm;
use App\Modules\Reference\Enums\PaymentMeans;
use App\Modules\Reference\Enums\UnitOfMeasure;
use App\Rules\DecimalString;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Body of a draft invoice, credit note or debit note, for create, update and preview; the type
 * comes from the route (invoices, credit-notes or debit-notes). A draft can be incomplete: what is
 * required to issue it is checked when it is issued. A new note names the accepted invoice it
 * corrects, and takes its customer, currency and payment terms from it. Decimals travel as strings.
 */
final class DocumentRequest extends FormRequest
{
    private const int MAX_LINES = 200;

    public function documentType(): DocumentType
    {
        return DocumentType::forRoute($this->route()?->getName());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $type = $this->documentType();

        $common = [
            'number_template_id' => [
                'nullable',
                'string',
                Rule::exists('number_templates', 'id')
                    ->where('company_id', $companyId)
                    ->where('document_type', $type->value),
            ],
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

        if (! $type->isNote()) {
            return [
                'contact_id' => ['nullable', 'string', Rule::exists('contacts', 'id')->where('company_id', $companyId)],
                'payment_form' => ['nullable', Rule::enum(PaymentForm::class)],
                'payment_means_code' => ['nullable', Rule::enum(PaymentMeans::class)],
                'due_date' => ['nullable', 'date_format:Y-m-d'],
                ...$common,
            ];
        }

        return [
            // Required to create a note; sent with an update it is ignored: a note keeps its invoice.
            'related_document_id' => [
                $this->creating() ? 'required' : 'nullable',
                'string',
                Rule::exists('documents', 'id')
                    ->where('company_id', $companyId)
                    ->where('type', DocumentType::Invoice->value)
                    ->where('status', 'issued')
                    ->where('electronic_status', 'accepted'),
            ],
            'reason_code' => ['nullable', Rule::enum($type === DocumentType::CreditNote ? CreditNoteConcept::class : DebitNoteConcept::class)],
            'reason_text' => ['nullable', 'string', 'max:5000'],
            ...$common,
        ];
    }

    /**
     * A voided invoice takes no more notes.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->creating() || ! $this->documentType()->isNote() || $validator->errors()->has('related_document_id')) {
                return;
            }

            $invoice = Document::query()->find($this->input('related_document_id'));

            if ($invoice?->balance_status === BalanceStatus::Voided) {
                $validator->errors()->add('related_document_id', "Invoice {$invoice->full_number} is voided and takes no more notes.");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'contact_id' => 'customer',
            'related_document_id' => 'invoice',
            'reason_code' => 'concept',
            'reason_text' => 'description',
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

    private function creating(): bool
    {
        return $this->route()?->getName() === $this->documentType()->path().'.store';
    }
}
