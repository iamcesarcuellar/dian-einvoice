<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\NumberingStatus;
use App\Modules\Billing\Enums\XmlStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Models\SigningCertificate;
use App\Modules\Dian\Services\InvoiceStamp;
use App\Modules\Dian\Services\InvoiceXmlGenerator;
use App\Modules\Dian\Services\IssueRequirements;
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
 * the next number, freezes the copies and stamps the CUFE. Any failure rolls everything back, so a
 * number is never skipped or repeated. Deadlocks are retried. Once committed, the UBL XML is
 * generated and signed; a failure there leaves the invoice issued and marked.
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
        private readonly IssueRequirements $requirements,
        private readonly InvoiceStamp $stamp,
        private readonly InvoiceXmlGenerator $xml,
    ) {}

    public function __invoke(string $documentId, ?User $actor = null): Document
    {
        $document = DB::transaction(function () use ($documentId, $actor): Document {
            // 1. The document. A second click waits here, then finds it issued.
            $document = Document::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if (! $document->isDraft()) {
                throw new ConflictHttpException('This invoice is already issued.');
            }

            $document->load('contact.municipality.department');

            // The system sets the moment of issue: Colombian date and time with its offset, UTC instant.
            $now = Carbon::now();
            $local = $now->copy()->setTimezone(NumberTemplate::TIMEZONE);
            $today = $local->toDateString();

            $company = $this->currentCompany->company();
            $settings = DianSetting::current();
            $lines = $this->pricing->storedLines($document);
            $withholdings = $this->pricing->storedWithholdings($document);

            $this->validate($document, $lines, $withholdings, $today, $settings);

            // Totals are recalculated on the server from the line copies, which were just checked
            // against the current taxes and withholdings. Nothing is written yet.
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
                'issue_time' => $local->format('H:i:sP'),
                'issued_at' => $now,
                'company_snapshot' => $this->snapshots->company($company),
                'customer_snapshot' => $this->snapshots->customer($document->contact),
                'numbering_snapshot' => $this->snapshots->numbering($template),
                'xml_status' => XmlStatus::Pending,
            ]);

            // 3. CUFE, software security code, QR URL and environment, from what was just frozen.
            $document->fill($this->stamp->stamp($document, $template, $settings))->save();

            DocumentEvent::create([
                'document_id' => $document->getKey(),
                'type' => DocumentEventType::Issued,
                'payload' => ['number' => $number, 'full_number' => $document->full_number, 'cufe' => $document->cufe],
                'occurred_at' => $now,
                'actor_id' => $actor?->getKey(),
            ]);

            $this->outbox->record($document->company_id, 'document', $document->getKey(), 'invoice.issued', [
                'document_id' => $document->getKey(),
                'full_number' => $document->full_number,
                'cufe' => $document->cufe,
                'issue_date' => $today,
                'contact_id' => $document->contact_id,
                'payable_amount' => $document->payable_amount,
                'amount_due' => $document->amount_due,
                'currency' => $document->currency,
            ]);

            return $document;
        }, self::ATTEMPTS);

        // 4. After the commit: the signed XML. It never throws; a failure is recorded on the invoice.
        return $this->xml->generate($document);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $withholdings
     */
    private function validate(Document $document, array $lines, array $withholdings, string $today, DianSetting $settings): void
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

        $errors = array_merge(
            $errors,
            $this->staleRates($lines, $withholdings),
            $this->requirements->missing($this->currentCompany->company(), $settings, SigningCertificate::current(), $this->numbering($document), $document->contact),
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Taxes and withholdings copied into the draft that are no longer active, or whose rate
     * changed since the draft was saved. The draft is never recalculated in silence: the user
     * reviews it, and saving it again takes the current rates.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $withholdings
     * @return array<string, list<string>>
     */
    private function staleRates(array $lines, array $withholdings): array
    {
        $errors = [];
        $taxes = Tax::query()->whereKey(array_filter(array_column($lines, 'tax_id')))->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            if ($line['tax_id'] === null) {
                continue;
            }

            $problem = $this->rateProblem($taxes->get($line['tax_id']), (string) $line['tax_name'], (string) $line['tax_code'], (string) $line['tax_rate']);

            if ($problem !== null) {
                $errors["lines.{$index}.tax_id"][] = $problem;
            }
        }

        $current = Withholding::query()->whereKey(array_filter(array_column($withholdings, 'withholding_id')))->get()->keyBy('id');

        foreach ($withholdings as $index => $withholding) {
            if ($withholding['withholding_id'] === null) {
                continue;
            }

            $problem = $this->rateProblem($current->get($withholding['withholding_id']), (string) $withholding['name'], (string) $withholding['dian_code'], (string) $withholding['rate']);

            if ($problem !== null) {
                $errors["withholdings.{$index}"][] = $problem;
            }
        }

        return $errors;
    }

    private function rateProblem(Tax|Withholding|null $rate, string $name, string $code, string $copiedRate): ?string
    {
        if ($rate === null || ! $rate->is_active) {
            return "{$name} is no longer active. Review the draft and choose another one.";
        }

        if ($rate->dian_code->value !== $code || ! BigDecimal::of($rate->rate)->isEqualTo($copiedRate)) {
            return sprintf(
                '%s changed from %s %% to %s %% since the draft was saved. Review the draft and save it again.',
                $name,
                BigDecimal::of($copiedRate)->strippedOfTrailingZeros(),
                BigDecimal::of($rate->rate)->strippedOfTrailingZeros(),
            );
        }

        return null;
    }

    /** The numbering the draft will take, read without a lock to check its technical key. */
    private function numbering(Document $document): ?NumberTemplate
    {
        $id = $this->numberingId($document);

        return $id === null ? null : NumberTemplate::query()->find($id);
    }

    private function numberingId(Document $document): ?string
    {
        return $document->number_template_id ?? NumberTemplate::query()
            ->where('document_type', DocumentType::Invoice)
            ->where('is_default', true)
            ->value('id');
    }

    private function lockNumbering(Document $document): NumberTemplate
    {
        $id = $this->numberingId($document);

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
