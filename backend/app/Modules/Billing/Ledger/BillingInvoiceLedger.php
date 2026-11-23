<?php

namespace App\Modules\Billing\Ledger;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Models\DocumentPayment;
use App\Modules\Billing\Services\InvoiceReceivables;
use App\Modules\Payments\Ports\AmountExceedsBalance;
use App\Modules\Payments\Ports\InvoiceLedger;
use App\Modules\Payments\Ports\InvoiceNotPayable;
use App\Modules\Payments\Ports\OpenInvoice;
use App\Modules\Payments\Ports\PaymentReference;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Billing's side of the invoice ledger the payments module uses. Each application is kept on the
 * invoice (document_payments) with the paid amount, the receivable and the payment status, and
 * shows on its timeline; nothing here reads the tables of Payments.
 */
final class BillingInvoiceLedger implements InvoiceLedger
{
    public function __construct(private readonly InvoiceReceivables $receivables) {}

    public function openInvoices(?string $contactId = null): array
    {
        $invoices = Document::query()
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Issued)
            ->where('electronic_status', ElectronicStatus::Accepted)
            ->where('receivable_amount', '!=', 0)
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId))
            ->orderByRaw('coalesce(due_date, issue_date)')
            ->orderBy('number')
            ->get();

        return array_values($invoices->map(fn (Document $invoice): OpenInvoice => $this->open($invoice))->all());
    }

    public function apply(string $invoiceId, string $contactId, string $amount, PaymentReference $payment): OpenInvoice
    {
        $invoice = Document::query()->whereKey($invoiceId)->lockForUpdate()->first();

        if ($invoice === null
            || $invoice->type !== DocumentType::Invoice
            || $invoice->status !== DocumentStatus::Issued
            || $invoice->electronic_status !== ElectronicStatus::Accepted
            || $invoice->contact_id !== $contactId) {
            throw new InvoiceNotPayable($invoice?->full_number === null ? __('billing::errors.not_payable') : __('billing::errors.not_payable_number', ['number' => $invoice->full_number]));
        }

        $value = BigDecimal::of($amount)->toScale(2);
        $balance = BigDecimal::of((string) $invoice->receivable_amount);

        if (! $value->isPositive()) {
            throw new LogicException('The amount to apply must be positive.');
        }

        if ($value->isGreaterThan($balance)) {
            throw new AmountExceedsBalance((string) $invoice->full_number, (string) $balance->toScale(2), (string) $value);
        }

        DocumentPayment::create([
            'document_id' => $invoice->getKey(),
            'payment_id' => $payment->id,
            'payment_number' => $payment->number,
            'payment_date' => $payment->date,
            'payment_means_code' => $payment->meansCode,
            'amount' => (string) $value,
            'status' => DocumentPayment::APPLIED,
            'applied_at' => Carbon::now(),
        ]);

        $invoice->fill(['paid_amount' => (string) BigDecimal::of((string) $invoice->paid_amount)->plus($value)])->save();
        $this->receivables->refresh($invoice);
        $this->timeline($invoice, DocumentEventType::PaymentApplied, $payment, $value);

        return $this->open($invoice);
    }

    public function release(string $invoiceId, PaymentReference $payment): OpenInvoice
    {
        $invoice = Document::query()->whereKey($invoiceId)->lockForUpdate()->firstOrFail();

        $application = DocumentPayment::query()
            ->where('document_id', $invoice->getKey())
            ->where('payment_id', $payment->id)
            ->where('status', DocumentPayment::APPLIED)
            ->first();

        if ($application === null) {
            throw new LogicException("Payment {$payment->number} is not applied to invoice {$invoice->full_number}.");
        }

        $value = BigDecimal::of((string) $application->amount);
        $application->fill(['status' => DocumentPayment::RELEASED, 'released_at' => Carbon::now()])->save();

        $invoice->fill(['paid_amount' => (string) BigDecimal::of((string) $invoice->paid_amount)->minus($value)])->save();
        $this->receivables->refresh($invoice);
        $this->timeline($invoice, DocumentEventType::PaymentReleased, $payment, $value);

        return $this->open($invoice);
    }

    private function timeline(Document $invoice, DocumentEventType $type, PaymentReference $payment, BigDecimal $amount): void
    {
        DocumentEvent::create([
            'document_id' => $invoice->getKey(),
            'type' => $type,
            'payload' => [
                'payment_id' => $payment->id,
                'number' => $payment->number,
                'amount' => (string) $amount,
                'balance' => (string) $invoice->receivable_amount,
            ],
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function open(Document $invoice): OpenInvoice
    {
        $amountDue = BigDecimal::of((string) $invoice->amount_due);
        $paid = BigDecimal::of((string) $invoice->paid_amount);
        $balance = BigDecimal::of((string) ($invoice->receivable_amount ?? $invoice->amount_due));
        // The notes moved the balance by the rest: amount due - paid - balance = credited - debited.
        $notes = $amountDue->minus($paid)->minus($balance);
        $customer = $invoice->customer_snapshot ?? [];

        return new OpenInvoice(
            id: $invoice->id,
            number: (string) $invoice->full_number,
            contactId: (string) $invoice->contact_id,
            customerName: (string) ($customer['legal_name'] ?? ''),
            customerIdentification: isset($customer['identification_number']) ? (string) $customer['identification_number'] : null,
            issueDate: (string) $invoice->issue_date?->toDateString(),
            dueDate: $invoice->due_date?->toDateString(),
            currency: $invoice->currency,
            amountDue: (string) $amountDue->toScale(2),
            credited: (string) ($notes->isPositive() ? $notes : BigDecimal::zero())->toScale(2),
            debited: (string) ($notes->isNegative() ? $notes->negated() : BigDecimal::zero())->toScale(2),
            paid: (string) $paid->toScale(2),
            balance: (string) $balance->toScale(2),
            daysPastDue: InvoiceReceivables::daysPastDue($invoice),
        );
    }
}
