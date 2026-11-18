<?php

namespace App\Modules\Delivery\Pdf;

/**
 * Everything the graphic representation of an invoice shows, already formatted for reading: built
 * by InvoiceRepresentationBuilder from the frozen copies of the document only, and drawn by a
 * DocumentRenderer. Labels come from the translation keys of delivery::pdf; the renderer writes the
 * page number with the key delivery::pdf.page.
 */
final readonly class InvoiceRepresentation
{
    /**
     * @param  array{legal_name: string, nit: string, address: ?string, city: ?string, phone: ?string, email: ?string, regime: string}  $company
     * @param  array{name: string, identification: string, address: ?string, city: ?string, email: ?string, phone: ?string}  $customer
     * @param  list<array{label: string, value: string}>  $details
     * @param  list<array{position: string, code: string, description: string, quantity: string, unit: string, unit_price: string, discount: string, tax: string, total: string}>  $lines
     * @param  list<array{label: string, detail: ?string, value: string, kind: string}>  $totals  kind: amount, deduction, total or net.
     * @param  array{authorization: string, cufe: string, representation: string, software: string, validation: string}  $footer
     * @param  array{title: string, author: string, subject: string, keywords: string}  $metadata
     */
    public function __construct(
        public string $title,
        public string $number,
        public string $testMark,
        public string $testNotice,
        public string $watermark,
        public array $company,
        public array $customer,
        public array $details,
        public array $lines,
        public array $totals,
        public string $amountInWords,
        public ?string $notes,
        public array $footer,
        public string $qrText,
        public array $metadata,
    ) {}
}
