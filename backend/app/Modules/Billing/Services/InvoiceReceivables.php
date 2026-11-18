<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\InvoicePaymentStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * What is left to collect on an issued invoice, calculated by the server: its net amount due, less
 * the net of its accepted credit notes, plus the net of its accepted debit notes, less the payments
 * applied and not voided. A negative receivable is a credit in favor of the customer. The invoice
 * stores it with its payment status, refreshed whenever a note is accepted or a payment is applied
 * or voided; overdue is calculated against today in Colombia.
 */
final class InvoiceReceivables
{
    /** Writes the receivable and the payment status of the invoice, which the caller has locked. */
    public function refresh(Document $invoice): void
    {
        $receivable = $this->calculate($invoice);
        $paid = BigDecimal::of((string) $invoice->paid_amount);

        $invoice->fill([
            'receivable_amount' => (string) $receivable,
            'payment_status' => match (true) {
                ! $paid->isPositive() => InvoicePaymentStatus::Unpaid,
                $receivable->isPositive() => InvoicePaymentStatus::PartiallyPaid,
                default => InvoicePaymentStatus::Paid,
            },
        ])->save();
    }

    public function calculate(Document $invoice): BigDecimal
    {
        $receivable = BigDecimal::of((string) $invoice->amount_due)->minus((string) $invoice->paid_amount);

        $notes = Document::query()
            ->where('related_document_id', $invoice->getKey())
            ->where('status', DocumentStatus::Issued)
            ->where('electronic_status', ElectronicStatus::Accepted)
            ->get(['id', 'type', 'amount_due']);

        foreach ($notes as $note) {
            $receivable = $note->type === DocumentType::CreditNote
                ? $receivable->minus((string) $note->amount_due)
                : $receivable->plus((string) $note->amount_due);
        }

        return $receivable->toScale(2);
    }

    /**
     * Days after the due date, or after the issue date when there is none (cash); zero or less is
     * not due yet.
     */
    public static function daysPastDue(Document $invoice): int
    {
        $due = $invoice->due_date ?? $invoice->issue_date;

        if ($due === null) {
            return 0;
        }

        $today = Carbon::now(NumberTemplate::TIMEZONE)->startOfDay();

        return (int) Carbon::parse($due->toDateString(), NumberTemplate::TIMEZONE)->startOfDay()->diffInDays($today, false);
    }

    /** An accepted invoice with something left to collect after its due date. */
    public static function overdue(Document $invoice): bool
    {
        return $invoice->type === DocumentType::Invoice
            && $invoice->electronic_status === ElectronicStatus::Accepted
            && $invoice->receivable_amount !== null
            && BigDecimal::of((string) $invoice->receivable_amount)->isPositive()
            && self::daysPastDue($invoice) > 0;
    }

    /** Today in Colombia, as the overdue filters compare dates. */
    public static function today(): string
    {
        return Carbon::now(NumberTemplate::TIMEZONE)->toDateString();
    }
}
