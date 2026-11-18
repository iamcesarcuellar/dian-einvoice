<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Identity\Models\User;
use App\Modules\Reference\Enums\PaymentForm;
use App\Modules\Reference\Enums\PaymentMeans;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Drafts of invoices, credit notes and debit notes: they can be saved incomplete, and every save
 * recalculates their totals. A note takes the customer, the currency and the payment terms of the
 * invoice it corrects, and they cannot change.
 */
final class DocumentDrafts
{
    /** Header fields of an invoice taken from the input. */
    private const array INVOICE_HEADER = [
        'contact_id',
        'number_template_id',
        'payment_form',
        'payment_means_code',
        'due_date',
        'notes',
    ];

    /** Header fields of a note taken from the input; the rest comes from its invoice. */
    private const array NOTE_HEADER = [
        'number_template_id',
        'reason_code',
        'reason_text',
        'notes',
    ];

    public function __construct(private readonly DocumentPricing $pricing) {}

    /**
     * @param  array<string, mixed>  $input  Validated draft input.
     */
    public function create(DocumentType $type, array $input, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($type, $input, $actor): Document {
            $invoice = $type->isNote() ? $this->invoice((string) $input['related_document_id']) : null;
            $header = $invoice !== null
                ? [...$this->inherited($invoice), ...Arr::only($input, self::NOTE_HEADER)]
                : ['payment_form' => PaymentForm::Cash, 'payment_means_code' => PaymentMeans::Cash, ...Arr::only($input, self::INVOICE_HEADER)];

            $document = new Document([
                'type' => $type,
                'status' => DocumentStatus::Draft,
                ...$header,
                'created_by' => $actor?->getKey(),
            ]);

            // The default numbering of the type, unless another one was chosen.
            $document->number_template_id ??= NumberTemplate::query()
                ->where('document_type', $type)
                ->where('is_default', true)
                ->value('id');

            $document->save();

            // A note keeps the tax and withholding rates frozen on its invoice.
            $this->pricing->apply(
                $document,
                $this->pricing->resolveLines($input['lines'] ?? [], $invoice),
                $this->pricing->resolveWithholdings($input['withholding_ids'] ?? [], $invoice),
            );

            DocumentEvent::create([
                'document_id' => $document->getKey(),
                'type' => DocumentEventType::Created,
                'occurred_at' => now(),
                'actor_id' => $actor?->getKey(),
            ]);

            return $document;
        });
    }

    /**
     * Partial update: header fields that are sent replace the stored ones, and sent lines or
     * withholdings replace all of them. The invoice of a note never changes.
     *
     * @param  array<string, mixed>  $input  Validated draft input.
     */
    public function update(Document $document, array $input): Document
    {
        return DB::transaction(function () use ($document, $input): Document {
            $noun = $document->type->noun();
            $draft = $this->lockDraft($document, "Issued {$noun}s cannot be edited.");

            $draft->fill(Arr::only($input, $draft->isNote() ? self::NOTE_HEADER : self::INVOICE_HEADER))->save();

            if (array_key_exists('lines', $input) || array_key_exists('withholding_ids', $input)) {
                $invoice = $draft->isNote() && $draft->related_document_id !== null ? $this->invoice($draft->related_document_id) : null;

                $this->pricing->apply(
                    $draft,
                    array_key_exists('lines', $input)
                        ? $this->pricing->resolveLines($input['lines'] ?? [], $invoice)
                        : $this->pricing->storedLines($draft),
                    array_key_exists('withholding_ids', $input)
                        ? $this->pricing->resolveWithholdings($input['withholding_ids'] ?? [], $invoice)
                        : $this->pricing->storedWithholdings($draft),
                );
            }

            return $draft;
        });
    }

    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            // Lines, taxes, withholdings and events go with it (foreign keys cascade).
            $this->lockDraft($document, "Issued {$document->type->noun()}s cannot be deleted.")->delete();
        });
    }

    /** The invoice a note corrects: the request checked it is an accepted invoice. */
    private function invoice(string $invoiceId): Document
    {
        return Document::query()->where('type', DocumentType::Invoice)->findOrFail($invoiceId);
    }

    /**
     * What a note takes from the invoice it corrects.
     *
     * @return array<string, mixed>
     */
    private function inherited(Document $invoice): array
    {
        return [
            'related_document_id' => $invoice->getKey(),
            'contact_id' => $invoice->contact_id,
            'currency' => $invoice->currency,
            'payment_form' => $invoice->payment_form,
            'payment_means_code' => $invoice->payment_means_code,
            'due_date' => $invoice->due_date?->toDateString(),
        ];
    }

    /**
     * Locks the row, so the draft cannot be issued while it changes, and checks it is still a draft.
     */
    private function lockDraft(Document $document, string $message): Document
    {
        $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isDraft()) {
            throw new ConflictHttpException($message);
        }

        return $locked;
    }
}
