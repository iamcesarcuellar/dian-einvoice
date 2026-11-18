<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Http\Resources\DocumentLineResource;
use App\Modules\Billing\Http\Resources\DocumentResource;
use App\Modules\Billing\Http\Resources\DocumentWithholdingResource;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentLine;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Http\Resources\ContactResource;
use App\Modules\Delivery\Support\ColombianFormat;
use App\Modules\Reference\Enums\CreditNoteConcept;
use Brick\Math\BigDecimal;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A credit or debit note not saved yet, for an accepted invoice: the customer, currency and payment
 * terms of the invoice, its withholdings, its balance, and its lines. A credit note starts from what
 * is left to credit, line by line: the lines of the invoice less the quantities its credit notes
 * already took (issued and not rejected), plus the lines of its accepted debit notes. A debit note
 * starts from the lines of the invoice as they are. A void starts from the same lines as a credit
 * note, with the void concept and a description already written.
 */
final class NotePrefill
{
    public function __construct(private readonly InvoiceBalance $balances) {}

    /**
     * @return array<string, mixed>
     */
    public function for(DocumentType $type, Document $invoice, bool $void = false): array
    {
        if (! $type->isNote()) {
            throw new LogicException('Only notes are prefilled from an invoice.');
        }

        if ($invoice->electronic_status !== ElectronicStatus::Accepted) {
            throw new ConflictHttpException("Invoice {$invoice->full_number} is not accepted by the local simulator, so it cannot take notes.");
        }

        if ($invoice->balance_status === BalanceStatus::Voided) {
            throw new ConflictHttpException("Invoice {$invoice->full_number} is voided and takes no more notes.");
        }

        $invoice->load(['lines.item', 'withholdings', 'contact.municipality.department']);
        $notes = Document::query()
            ->where('related_document_id', $invoice->getKey())
            ->where('status', DocumentStatus::Issued)
            ->with('lines.item')
            ->get();

        $balance = $this->balances->of($invoice, null, $notes);
        $void = $void && $type === DocumentType::CreditNote;

        if ($type === DocumentType::CreditNote && ! BigDecimal::of($balance['remaining_to_credit'])->isPositive()) {
            throw new ConflictHttpException("Nothing is left to credit on invoice {$invoice->full_number}.");
        }

        $lines = $type === DocumentType::CreditNote ? $this->remainingLines($invoice, $notes) : $invoice->lines->all();

        return [
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'related_document_id' => $invoice->getKey(),
            'related_document' => DocumentResource::reference($invoice),
            'contact_id' => $invoice->contact_id,
            'contact' => $invoice->contact === null ? null : ContactResource::make($invoice->contact),
            'customer_name' => $invoice->customer_snapshot['legal_name'] ?? null,
            'currency' => $invoice->currency,
            'payment_form' => $invoice->payment_form,
            'payment_means_code' => $invoice->payment_means_code,
            'due_date' => $invoice->due_date?->toDateString(),
            'number_template_id' => NumberTemplate::query()->where('document_type', $type)->where('is_default', true)->value('id'),
            'reason_code' => $void ? CreditNoteConcept::Void->value : null,
            'reason_text' => $void ? sprintf(
                'Void of invoice %s of %s: all that was left to credit, %s, is credited.',
                $invoice->full_number,
                $invoice->issue_date?->toDateString(),
                ColombianFormat::money($balance['remaining_to_credit']),
            ) : null,
            'notes' => null,
            'lines' => DocumentLineResource::collection($lines),
            'withholdings' => DocumentWithholdingResource::collection($invoice->withholdings),
            'balance' => $balance,
        ];
    }

    /**
     * The lines of the invoice less what its live credit notes took, matched by item, code,
     * description, price, discount and tax; plus the lines of its accepted debit notes.
     *
     * @param  iterable<Document>  $notes
     * @return list<DocumentLine>
     */
    private function remainingLines(Document $invoice, iterable $notes): array
    {
        $remaining = [];

        foreach ($invoice->lines as $line) {
            $remaining[$this->key($line)][] = clone $line;
        }

        foreach ($notes as $note) {
            $live = $note->type === DocumentType::CreditNote && $note->electronic_status !== ElectronicStatus::Rejected;
            $debit = $note->type === DocumentType::DebitNote && $note->electronic_status === ElectronicStatus::Accepted;

            foreach ($note->lines as $noteLine) {
                if ($debit) {
                    $remaining[$this->key($noteLine)][] = clone $noteLine;

                    continue;
                }

                if (! $live) {
                    continue;
                }

                $left = BigDecimal::of((string) $noteLine->quantity);

                foreach ($remaining[$this->key($noteLine)] ?? [] as $candidate) {
                    if (! $left->isPositive()) {
                        break;
                    }

                    $quantity = BigDecimal::of((string) $candidate->quantity);
                    $taken = $quantity->isLessThan($left) ? $quantity : $left;
                    $candidate->quantity = (string) $quantity->minus($taken);
                    $left = $left->minus($taken);
                }
            }
        }

        return array_values(array_filter(
            array_merge(...array_values($remaining ?: [[]])),
            fn (DocumentLine $line): bool => BigDecimal::of((string) $line->quantity)->isPositive(),
        ));
    }

    private function key(DocumentLine $line): string
    {
        return implode('|', [
            $line->item_id,
            $line->code,
            mb_strtolower(trim((string) $line->description)),
            (string) BigDecimal::of((string) $line->unit_price)->strippedOfTrailingZeros(),
            (string) BigDecimal::of((string) $line->discount_rate)->strippedOfTrailingZeros(),
            $line->tax_id,
        ]);
    }
}
