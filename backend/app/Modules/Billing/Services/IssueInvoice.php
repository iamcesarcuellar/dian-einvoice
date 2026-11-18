<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\NumberingStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\PaymentForm;
use App\Support\Outbox\Outbox;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Issues a draft invoice in one database transaction. It locks the document row, then the
 * numbering row (always in that order, so concurrent issues queue instead of deadlocking), takes
 * the next number and freezes the copies. Any failure rolls everything back, so a number is never
 * skipped or repeated. Deadlocks are retried.
 */
final class IssueInvoice
{
    /** Attempts when the database reports a deadlock. */
    private const int ATTEMPTS = 5;

    public function __construct(
        private readonly DocumentPricing $pricing,
        private readonly DocumentSnapshots $snapshots,
        private readonly CurrentCompany $currentCompany,
        private readonly Outbox $outbox,
    ) {}

    public function __invoke(string $documentId, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($documentId, $actor): Document {
            // 1. The document. A second click waits here, then finds it issued.
            $document = Document::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if (! $document->isDraft()) {
                throw new ConflictHttpException('This invoice is already issued.');
            }

            $document->load('contact.municipality.department');

            // The system sets the moment of issue: Colombian date, UTC instant.
            $now = Carbon::now();
            $today = $now->copy()->setTimezone(NumberTemplate::TIMEZONE)->toDateString();

            $lines = $this->pricing->storedLines($document);
            $withholdings = $this->pricing->storedWithholdings($document);
            $this->validate($document, $lines, $today);

            // Totals are recalculated on the server from the line copies. Nothing is written yet.
            if (! $this->pricing->calculate($lines, $withholdings)->totals->payableAmount->isPositive()) {
                throw ValidationException::withMessages(['lines' => ['The total must be greater than zero.']]);
            }

            // 2. The numbering, locked after the document. From here on every write is serialized
            // by this lock, so concurrent issues cannot deadlock on the document tables.
            $template = $this->lockNumbering($document);
            $number = $template->next_number;
            $template->next_number = $number + 1;
            $template->save();

            $this->pricing->apply($document, $lines, $withholdings);

            $prefix = $template->prefix ?? '';

            $document->fill([
                'status' => DocumentStatus::Issued,
                'number_template_id' => $template->getKey(),
                'prefix' => $prefix,
                'number' => $number,
                'full_number' => $prefix.$number,
                'issue_date' => $today,
                'issued_at' => $now,
                'company_snapshot' => $this->snapshots->company($this->currentCompany->company()),
                'customer_snapshot' => $this->snapshots->customer($document->contact),
                'numbering_snapshot' => $this->snapshots->numbering($template),
            ])->save();

            DocumentEvent::create([
                'document_id' => $document->getKey(),
                'type' => DocumentEventType::Issued,
                'payload' => ['number' => $number, 'full_number' => $document->full_number],
                'occurred_at' => $now,
                'actor_id' => $actor?->getKey(),
            ]);

            $this->outbox->record($document->company_id, 'document', $document->getKey(), 'invoice.issued', [
                'document_id' => $document->getKey(),
                'full_number' => $document->full_number,
                'issue_date' => $today,
                'contact_id' => $document->contact_id,
                'payable_amount' => $document->payable_amount,
                'amount_due' => $document->amount_due,
                'currency' => $document->currency,
            ]);

            return $document;
        }, self::ATTEMPTS);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function validate(Document $document, array $lines, string $today): void
    {
        $errors = [];

        if ($document->contact === null) {
            $errors['contact_id'][] = 'Choose the customer.';
        } elseif (! $document->contact->is_active) {
            $errors['contact_id'][] = 'The customer is inactive. Activate it or choose another one.';
        }

        if ($lines === []) {
            $errors['lines'][] = 'Add at least one line.';
        }

        foreach ($lines as $index => $line) {
            if (blank($line['description'])) {
                $errors["lines.{$index}.description"][] = 'Describe the line.';
            }

            if (! BigDecimal::of($line['quantity'])->isPositive()) {
                $errors["lines.{$index}.quantity"][] = 'The quantity must be greater than zero.';
            }

            if (BigDecimal::of($line['unit_price'])->isNegative()) {
                $errors["lines.{$index}.unit_price"][] = 'The unit price cannot be negative.';
            }
        }

        if ($document->payment_form === null) {
            $errors['payment_form'][] = 'Choose the payment form.';
        }

        if ($document->payment_means_code === null) {
            $errors['payment_means_code'][] = 'Choose the payment means.';
        }

        if ($document->payment_form === PaymentForm::Credit) {
            if ($document->due_date === null) {
                $errors['due_date'][] = 'A credit invoice needs a due date.';
            } elseif ($document->due_date->toDateString() < $today) {
                $errors['due_date'][] = 'The due date cannot be before today.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function lockNumbering(Document $document): NumberTemplate
    {
        $id = $document->number_template_id ?? NumberTemplate::query()
            ->where('document_type', DocumentType::Invoice)
            ->where('is_default', true)
            ->value('id');

        $template = $id === null ? null : NumberTemplate::query()->whereKey($id)->lockForUpdate()->first();

        if ($template === null) {
            throw ValidationException::withMessages(['number_template_id' => ['Choose the numbering for the invoice.']]);
        }

        $name = trim(($template->prefix ?? '').' '.$template->from_number.' to '.$template->to_number);

        $problem = match (true) {
            $template->document_type !== DocumentType::Invoice => 'This numbering is not for invoices.',
            ! $template->is_active => "The numbering {$name} is inactive. Activate it or choose another one.",
            default => match ($template->status()) {
                NumberingStatus::Exhausted => "The numbering {$name} has no numbers left. Add the next resolution in Settings.",
                NumberingStatus::Expired => "The numbering {$name} expired on {$template->valid_to?->toDateString()}. Add the next resolution in Settings.",
                NumberingStatus::Upcoming => "The numbering {$name} is valid from {$template->valid_from?->toDateString()}.",
                NumberingStatus::Active => null,
            },
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['number_template_id' => [$problem]]);
        }

        return $template;
    }
}
