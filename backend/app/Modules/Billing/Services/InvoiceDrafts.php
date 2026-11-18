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
 * Draft invoices: they can be saved incomplete, and every save recalculates their totals.
 */
final class InvoiceDrafts
{
    /** Header fields taken from the input. */
    private const array HEADER = [
        'contact_id',
        'number_template_id',
        'payment_form',
        'payment_means_code',
        'due_date',
        'notes',
    ];

    public function __construct(private readonly DocumentPricing $pricing) {}

    /**
     * @param  array<string, mixed>  $input  Validated draft input.
     */
    public function create(array $input, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($input, $actor): Document {
            $document = new Document([
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Draft,
                'payment_form' => PaymentForm::Cash,
                'payment_means_code' => PaymentMeans::Cash,
                ...Arr::only($input, self::HEADER),
                'created_by' => $actor?->getKey(),
            ]);

            // The default invoice numbering, unless another one was chosen.
            $document->number_template_id ??= NumberTemplate::query()
                ->where('document_type', DocumentType::Invoice)
                ->where('is_default', true)
                ->value('id');

            $document->save();

            $this->pricing->apply(
                $document,
                $this->pricing->resolveLines($input['lines'] ?? []),
                $this->pricing->resolveWithholdings($input['withholding_ids'] ?? []),
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
     * withholdings replace all of them.
     *
     * @param  array<string, mixed>  $input  Validated draft input.
     */
    public function update(Document $document, array $input): Document
    {
        return DB::transaction(function () use ($document, $input): Document {
            $draft = $this->lockDraft($document, 'Issued invoices cannot be edited.');

            $draft->fill(Arr::only($input, self::HEADER))->save();

            if (array_key_exists('lines', $input) || array_key_exists('withholding_ids', $input)) {
                $this->pricing->apply(
                    $draft,
                    array_key_exists('lines', $input)
                        ? $this->pricing->resolveLines($input['lines'] ?? [])
                        : $this->pricing->storedLines($draft),
                    array_key_exists('withholding_ids', $input)
                        ? $this->pricing->resolveWithholdings($input['withholding_ids'] ?? [])
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
            $this->lockDraft($document, 'Issued invoices cannot be deleted.')->delete();
        });
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
