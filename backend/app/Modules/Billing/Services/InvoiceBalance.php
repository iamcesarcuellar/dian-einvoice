<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\BillingEvents;
use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Delivery\Support\ColombianFormat;
use App\Modules\Reference\Enums\CreditNoteConcept;
use App\Support\Outbox\Outbox;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The balance of an invoice after its notes, calculated on the server from the payable amounts:
 * credited and debited count the notes the local simulator accepted; what is left to credit also
 * subtracts the credit notes still on their way (issued, processing or failed, not rejected), so
 * two credit notes can never credit more than the invoice plus its accepted debit notes. Less than
 * one peso left counts as nothing left: the invoice is credited in full, and a void may leave it.
 */
final class InvoiceBalance
{
    /** Less than this left to credit counts as credited in full. */
    public const string ONE_PESO = '1.00';

    public function __construct(
        private readonly InvoiceReceivables $receivables,
        private readonly Outbox $outbox,
    ) {}

    /**
     * $notes, when given, are the notes of the invoice already loaded; otherwise they are read.
     *
     * @param  iterable<Document>|null  $notes
     * @return array{total: string, credited: string, debited: string, balance: string, pending_credits: string, remaining_to_credit: string, can_credit: bool}
     */
    public function of(Document $invoice, ?string $exceptNoteId = null, ?iterable $notes = null): array
    {
        $credited = BigDecimal::zero();
        $debited = BigDecimal::zero();
        $committed = BigDecimal::zero();

        foreach ($notes ?? $this->issuedNotes($invoice) as $note) {
            if ($note->status !== DocumentStatus::Issued || $note->getKey() === $exceptNoteId) {
                continue;
            }

            $amount = BigDecimal::of((string) $note->payable_amount);
            $accepted = $note->electronic_status === ElectronicStatus::Accepted;

            if ($note->type === DocumentType::CreditNote) {
                $credited = $accepted ? $credited->plus($amount) : $credited;
                $committed = $note->electronic_status === ElectronicStatus::Rejected ? $committed : $committed->plus($amount);
            } elseif ($accepted) {
                $debited = $debited->plus($amount);
            }
        }

        $total = BigDecimal::of((string) $invoice->payable_amount);
        $remaining = $total->plus($debited)->minus($committed);

        return [
            'total' => (string) $total->toScale(2),
            'credited' => (string) $credited->toScale(2),
            'debited' => (string) $debited->toScale(2),
            'balance' => (string) $total->plus($debited)->minus($credited)->toScale(2),
            'pending_credits' => (string) $committed->minus($credited)->toScale(2),
            'remaining_to_credit' => (string) ($remaining->isNegative() ? BigDecimal::zero() : $remaining)->toScale(2),
            // One peso or more left: a new credit note makes sense.
            'can_credit' => $remaining->isGreaterThanOrEqualTo(self::ONE_PESO),
        ];
    }

    /**
     * Checks, with the invoice locked, that a credit note fits what is left to credit, and that a
     * void covers all of it. Debit notes have no ceiling.
     */
    public function assertFits(Document $note, Document $invoice, BigDecimal $payable): void
    {
        if ($note->type !== DocumentType::CreditNote) {
            return;
        }

        $remaining = BigDecimal::of($this->of($invoice, $note->id)['remaining_to_credit']);

        if ($payable->isGreaterThan($remaining)) {
            throw ValidationException::withMessages(['lines' => [sprintf(
                'The credit note is for %s, more than the %s left to credit on invoice %s.',
                ColombianFormat::money((string) $payable),
                ColombianFormat::money((string) $remaining),
                $invoice->full_number,
            )]]);
        }

        // A void takes all that is left; less than one peso may stay, and then counts as credited.
        if ($note->reason_code === CreditNoteConcept::Void->value && $remaining->minus($payable)->isGreaterThanOrEqualTo(self::ONE_PESO)) {
            throw ValidationException::withMessages(['reason_code' => [sprintf(
                'A credit note that voids invoice %s must be for all that is left to credit, %s, or less than one peso below it; this one is for %s.',
                $invoice->full_number,
                ColombianFormat::money((string) $remaining),
                ColombianFormat::money((string) $payable),
            )]]);
        }
    }

    /**
     * The balance status from the accepted notes, written on the locked invoice with a timeline entry
     * of the note: open, partially credited, credited when less than one peso is left, or voided by an
     * accepted credit note with the void concept. Its receivable and payment status follow. The
     * moment an invoice becomes voided, invoice.voided goes to the outbox for integrations.
     */
    public function noteAccepted(Document $invoice, Document $note): void
    {
        $voided = $note->type === DocumentType::CreditNote && $note->reason_code === CreditNoteConcept::Void->value;
        $wasVoided = $invoice->balance_status === BalanceStatus::Voided;
        $balance = $this->of($invoice);
        $status = match (true) {
            $voided || $invoice->balance_status === BalanceStatus::Voided => BalanceStatus::Voided,
            ! BigDecimal::of($balance['credited'])->isPositive() => BalanceStatus::Open,
            BigDecimal::of($balance['balance'])->isLessThan(self::ONE_PESO) => BalanceStatus::Credited,
            default => BalanceStatus::PartiallyCredited,
        };

        $invoice->fill(['balance_status' => $status])->save();
        $this->receivables->refresh($invoice);

        DocumentEvent::create([
            'document_id' => $invoice->getKey(),
            'type' => DocumentEventType::NoteAccepted,
            'payload' => [
                'note_id' => $note->getKey(),
                'note_type' => $note->type->value,
                'number' => $note->full_number,
                'amount' => (string) $note->payable_amount,
                'voided' => $voided,
                'balance_status' => $status->value,
            ],
            'occurred_at' => Carbon::now(),
        ]);

        if ($status === BalanceStatus::Voided && ! $wasVoided) {
            $this->outbox->record($invoice->company_id, 'document', $invoice->id, BillingEvents::INVOICE_VOIDED, [
                'document_id' => $invoice->getKey(),
                'number' => $invoice->full_number,
                'note_id' => $note->getKey(),
                'note_number' => $note->full_number,
            ]);
        }
    }

    /**
     * @return iterable<Document>
     */
    public function issuedNotes(Document $invoice): iterable
    {
        return Document::query()
            ->where('related_document_id', $invoice->getKey())
            ->where('status', DocumentStatus::Issued)
            ->orderBy('issued_at')
            ->get();
    }
}
