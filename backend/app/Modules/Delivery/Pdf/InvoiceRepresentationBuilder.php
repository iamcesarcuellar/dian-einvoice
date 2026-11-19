<?php

namespace App\Modules\Delivery\Pdf;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentLine;
use App\Modules\Billing\Models\DocumentTax;
use App\Modules\Billing\Models\DocumentWithholding;
use App\Modules\Delivery\Support\AmountInWords;
use App\Modules\Delivery\Support\ColombianFormat;
use App\Modules\Delivery\Support\QrContent;
use App\Modules\Dian\Ubl\ApplicationResponseSummary;
use App\Modules\Reference\Enums\CreditNoteConcept;
use App\Modules\Reference\Enums\DebitNoteConcept;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\WithholdingType;
use App\Support\Storage\DocumentStorage;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Lang;
use LogicException;

/**
 * The graphic representation of an accepted invoice, credit note or debit note, built only from
 * what was frozen on the document: its company, customer and numbering copies, its line copies, tax
 * subtotals, withholdings and totals, its CUFE or CUDE and QR URL, the ApplicationResponse of its
 * validation, and for a note the copy of the invoice it corrects with the concept of the correction.
 * Live company, customer, item or tax data is never read, and nothing is recalculated.
 *
 * @phpstan-import-type CompanySnapshot from Document
 * @phpstan-import-type CustomerSnapshot from Document
 * @phpstan-import-type NumberingSnapshot from Document
 */
final class InvoiceRepresentationBuilder
{
    public function __construct(private readonly DocumentStorage $storage) {}

    public function build(Document $document): InvoiceRepresentation
    {
        $number = $document->full_number;
        $cufe = $document->cufe;
        $responsePath = $document->dian_response_path;

        if ($document->isDraft() || $cufe === null || $number === null) {
            throw new LogicException('Only issued documents with a CUFE or CUDE have a graphic representation.');
        }

        if ($responsePath === null) {
            throw new LogicException('The document has no ApplicationResponse: it was not validated.');
        }

        $type = $document->type->value;

        $document->loadMissing(['lines', 'taxes', 'withholdings']);

        $company = $document->companySnapshot();
        $customer = $document->customerSnapshot();
        $validation = ApplicationResponseSummary::fromXml($this->storage->get($responsePath));
        $nit = ColombianFormat::nit($company['identification_number'], $company['check_digit']);

        return new InvoiceRepresentation(
            title: __("delivery::pdf.titles.{$type}"),
            number: $number,
            testMark: __('delivery::pdf.test_mark'),
            testNotice: __('delivery::pdf.test_notice'),
            watermark: __('delivery::pdf.watermark'),
            company: [
                'legal_name' => $company['legal_name'],
                'nit' => (string) __('delivery::pdf.nit_value', ['nit' => $nit]),
                'address' => $company['address'],
                'city' => $this->place($company),
                'phone' => $company['phone'],
                'email' => $company['email'],
                'regime' => (string) __('delivery::pdf.regime', [
                    'scheme' => $this->code('tax_schemes', $company['tax_scheme']),
                    'responsibilities' => $this->responsibilities($company['fiscal_responsibilities']),
                ]),
            ],
            customer: [
                'name' => $customer['legal_name'],
                'identification' => $this->identification($customer),
                'address' => $customer['address'],
                'city' => $this->place($customer),
                'email' => $customer['email'],
                'phone' => $customer['phone'],
            ],
            details: $this->details($document),
            lines: array_values($document->lines->values()->map(fn (DocumentLine $line, int $index): array => $this->line($line, $index))->all()),
            totals: $this->totals($document),
            amountInWords: AmountInWords::pesos((string) $document->payable_amount),
            notes: filled($document->notes) ? (string) $document->notes : null,
            footer: [
                'authorization' => $document->isNote()
                    ? $this->noteNumbering($document->numbering_snapshot ?? [])
                    : $this->authorization($document->numbering_snapshot ?? []),
                'key_label' => $document->keyName(),
                'cufe' => $cufe,
                'representation' => (string) __("delivery::pdf.representations.{$type}"),
                'software' => (string) __('delivery::pdf.software', ['name' => $company['legal_name'], 'nit' => $nit]),
                'validation' => (string) __('delivery::pdf.validation', [
                    'date' => ColombianFormat::date($validation->issueDate),
                    'time' => $validation->issueTime,
                ]),
            ],
            qrText: QrContent::for($document),
            metadata: [
                'title' => __('delivery::pdf.metadata_title', ['title' => __("delivery::pdf.titles.{$type}"), 'number' => $number]),
                'author' => $company['legal_name'],
                'subject' => __('delivery::pdf.metadata_subject', ['title' => mb_strtolower(__("delivery::pdf.titles.{$type}"))]),
                'keywords' => $document->keyName().' '.$cufe,
            ],
            reference: $document->isNote() ? $this->reference($document) : null,
        );
    }

    /**
     * The invoice a note corrects, from the copy frozen on issue, and the concept of the correction
     * with the name of the official list.
     *
     * @return array{title: string, invoice: string, cufe_label: string, cufe: string, concept: string, description: string}
     */
    private function reference(Document $document): array
    {
        $invoice = $document->related_snapshot ?? [];
        $concept = $document->type === DocumentType::CreditNote
            ? CreditNoteConcept::tryFrom((string) $document->reason_code)
            : DebitNoteConcept::tryFrom((string) $document->reason_code);

        return [
            'title' => __('delivery::pdf.reference.title'),
            'invoice' => __('delivery::pdf.reference.invoice', [
                'number' => (string) ($invoice['full_number'] ?? '—'),
                'date' => ColombianFormat::date($invoice['issue_date'] ?? null) ?: '—',
            ]),
            'cufe_label' => __('delivery::pdf.reference.cufe'),
            'cufe' => (string) ($invoice['cufe'] ?? '—'),
            'concept' => __('delivery::pdf.reference.concept', [
                'code' => (string) $document->reason_code,
                'name' => $concept === null ? '—' : __("delivery::codes.{$document->type->value}_concepts.{$concept->value}"),
            ]),
            'description' => trim((string) $document->reason_text),
        ];
    }

    /**
     * Notes need no DIAN resolution: the legend names the numbering of the issuer for the type.
     *
     * @param  NumberingSnapshot|array{}  $numbering
     */
    private function noteNumbering(array $numbering): string
    {
        return __('delivery::pdf.note_numbering', [
            'prefix' => (string) ($numbering['prefix'] ?? '—'),
            'from' => (string) ($numbering['from_number'] ?? '—'),
            'to' => (string) ($numbering['to_number'] ?? '—'),
        ]);
    }

    /**
     * Date and time of issue, due date, payment form and payment means.
     *
     * @return list<array{label: string, value: string}>
     */
    private function details(Document $document): array
    {
        $time = (string) $document->issue_time;

        return [
            ['label' => __('delivery::pdf.issued'), 'value' => __('delivery::pdf.issued_value', [
                'date' => ColombianFormat::date($document->issue_date),
                'time' => substr($time, 0, 8),
                'offset' => substr($time, 8) ?: '-05:00',
            ])],
            ...($document->isNote() ? [] : [['label' => __('delivery::pdf.due_date'), 'value' => $document->due_date === null ? __('delivery::pdf.no_due_date') : ColombianFormat::date($document->due_date)]]),
            ['label' => __('delivery::pdf.payment_form'), 'value' => $this->code('payment_forms', $document->payment_form?->value)],
            ['label' => __('delivery::pdf.payment_means'), 'value' => $this->code('payment_means', $document->payment_means_code?->value)],
        ];
    }

    /**
     * @return array{position: string, code: string, description: string, quantity: string, unit: string, unit_price: string, discount: string, tax: string, total: string}
     */
    private function line(DocumentLine $line, int $index): array
    {
        $discount = (string) $line->discount_rate;

        return [
            'position' => (string) ($index + 1),
            'code' => filled($line->code) ? (string) $line->code : '—',
            'description' => (string) $line->description,
            'quantity' => ColombianFormat::decimal((string) $line->quantity),
            'unit' => $this->code('units', $line->unit_code),
            'unit_price' => ColombianFormat::money((string) $line->unit_price, 6),
            'discount' => BigDecimal::of($discount)->isZero() ? '—' : ColombianFormat::percent($discount),
            'tax' => filled($line->tax_name) ? (string) $line->tax_name : __('delivery::pdf.excluded'),
            'total' => ColombianFormat::money((string) $line->line_extension_amount),
        ];
    }

    /**
     * Subtotal, discounts, each tax with its base, total, each withholding with its base and the net
     * the company receives, as the totals panel of the web app shows them.
     *
     * @return list<array{label: string, detail: ?string, value: string, kind: string}>
     */
    private function totals(Document $document): array
    {
        $rows = [
            ['label' => (string) __('delivery::pdf.subtotal'), 'detail' => null, 'value' => ColombianFormat::money((string) $document->subtotal_amount), 'kind' => 'amount'],
            ['label' => (string) __('delivery::pdf.discounts'), 'detail' => null, 'value' => ColombianFormat::money((string) $document->discount_total), 'kind' => 'deduction'],
        ];

        foreach ($document->taxes as $tax) {
            /** @var DocumentTax $tax */
            $rows[] = [
                'label' => $tax->name.' '.ColombianFormat::percent((string) $tax->rate),
                'detail' => (string) __('delivery::pdf.on', ['base' => ColombianFormat::money((string) $tax->taxable_amount)]),
                'value' => ColombianFormat::money((string) $tax->tax_amount),
                'kind' => 'amount',
            ];
        }

        $rows[] = ['label' => (string) __('delivery::pdf.total'), 'detail' => null, 'value' => ColombianFormat::money((string) $document->payable_amount), 'kind' => 'total'];

        foreach ($document->withholdings as $withholding) {
            /** @var DocumentWithholding $withholding */
            // ReteICA is written per thousand, as in the XML (table 13.2.2); the others in percent.
            $rate = WithholdingType::tryFrom((string) $withholding->dian_code)?->perThousand()
                ? ColombianFormat::perMil((string) $withholding->rate)
                : ColombianFormat::percent((string) $withholding->rate);

            $rows[] = [
                'label' => (string) $withholding->name,
                'detail' => (string) __('delivery::pdf.rate_on', ['rate' => $rate, 'base' => ColombianFormat::money((string) $withholding->base_amount)]),
                'value' => ColombianFormat::money((string) $withholding->amount),
                'kind' => 'deduction',
            ];
        }

        $rows[] = ['label' => (string) __('delivery::pdf.net'), 'detail' => null, 'value' => ColombianFormat::money((string) $document->amount_due), 'kind' => 'net'];

        return $rows;
    }

    /**
     * The numbering authorization legend: resolution, its date, prefix, range and validity, all from
     * the copy of the numbering frozen on issue.
     *
     * @param  NumberingSnapshot|array{}  $numbering
     */
    private function authorization(array $numbering): string
    {
        $values = [
            'number' => (string) ($numbering['resolution_number'] ?? '—'),
            'date' => ColombianFormat::date($numbering['resolution_date'] ?? null) ?: '—',
            'prefix' => (string) ($numbering['prefix'] ?? ''),
            'from' => (string) ($numbering['from_number'] ?? '—'),
            'to' => (string) ($numbering['to_number'] ?? '—'),
            'valid_from' => ColombianFormat::date($numbering['valid_from'] ?? null) ?: '—',
            'valid_to' => ColombianFormat::date($numbering['valid_to'] ?? null) ?: '—',
        ];

        return filled($values['prefix'])
            ? __('delivery::pdf.authorization', $values)
            : __('delivery::pdf.authorization_no_prefix', $values);
    }

    /**
     * @param  CustomerSnapshot  $party
     */
    private function identification(array $party): string
    {
        $type = $party['identification_type'];
        $number = $party['identification_number'];

        if ($type === IdentificationType::Nit->value && ($party['check_digit'] ?? null) !== null) {
            $number = ColombianFormat::nit($number, $party['check_digit']);
        }

        return $this->code('identification_short', $type).' '.$number;
    }

    /**
     * City and department, or the country of a party abroad.
     *
     * @param  CompanySnapshot|CustomerSnapshot  $party
     */
    private function place(array $party): ?string
    {
        $municipality = $party['municipality'];

        if ($municipality !== null && filled($municipality['name'])) {
            return implode(', ', array_filter([$municipality['name'], $municipality['department_name']]));
        }

        return filled($party['country_code']) ? $party['country_code'] : null;
    }

    /**
     * @param  list<string>  $codes
     */
    private function responsibilities(array $codes): string
    {
        if ($codes === []) {
            return '—';
        }

        return implode(', ', array_map(fn (string $code): string => "{$code} ".$this->code('fiscal_responsibilities', $code), $codes));
    }

    /** The English name of a DIAN code, from the translation keys; the code itself when unknown. */
    private function code(string $list, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        $key = "delivery::codes.{$list}.{$code}";

        return Lang::has($key) ? (string) __($key) : $code;
    }
}
