<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\InvoicePaymentStatus;
use App\Modules\Billing\Enums\NumberingStatus;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Dian\Models\DianSetting;
use App\Modules\Dian\Models\SigningCertificate;
use App\Modules\Dian\Pipeline\ChainEvents;
use App\Modules\Dian\Services\DocumentStamp;
use App\Modules\Dian\Services\IssueRequirements;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Modules\Reference\Enums\CreditNoteConcept;
use App\Modules\Reference\Enums\DebitNoteConcept;
use App\Modules\Reference\Enums\PaymentForm;
use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;
use App\Support\Outbox\Outbox;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Issues a draft invoice, credit note or debit note in one database transaction. It locks the
 * document row, then, for a note, the invoice it corrects, then the numbering row (always in that
 * order, so concurrent issues queue instead of deadlocking). It takes the next number, freezes the
 * copies and stamps the CUFE of an invoice or the CUDE of a note. The lock on the invoice makes the
 * credit ceiling hold between notes issued at the same time. Any failure rolls everything back, so
 * a number is never skipped or repeated; deadlocks are retried. The same transaction records the
 * issued event, which starts the electronic document chain: generating and signing the XML,
 * sending it to the DIAN (the local simulator) and recording the answer all run in the queue.
 *
 * @phpstan-import-type LineCopy from DocumentPricing
 * @phpstan-import-type WithholdingCopy from DocumentPricing
 */
final class IssueDocument
{
    /** Attempts when the database reports a deadlock. */
    private const int ATTEMPTS = 5;

    /**
     * Length of the description of a correction, written by the user. It goes into cbc:Note of the
     * XML (5 to 5000 characters, CAD11 and DAD11), while DiscrepancyResponse/Description carries the
     * official name of the concept (CBF04, DBF04); 20 keeps it a real explanation.
     */
    private const int REASON_MIN = 20;

    private const int REASON_MAX = 5000;

    public function __construct(
        private readonly DocumentPricing $pricing,
        private readonly DocumentSnapshots $snapshots,
        private readonly CurrentCompany $currentCompany,
        private readonly Outbox $outbox,
        private readonly IssueRequirements $requirements,
        private readonly DocumentStamp $stamp,
        private readonly InvoiceBalance $balances,
    ) {}

    public function __invoke(string $documentId, ?User $actor = null): Document
    {
        $started = hrtime(true);
        $document = DB::transaction(function () use ($documentId, $actor): Document {
            // 1. The document. A second click waits here, then finds it issued.
            $document = Document::query()->whereKey($documentId)->lockForUpdate()->firstOrFail();

            if (! $document->isDraft()) {
                throw new ConflictHttpException(__("billing::errors.issued.{$document->type->value}"));
            }

            $document->load('contact.municipality.department');

            // 2. A note: the invoice it corrects, locked after the note.
            $invoice = $document->isNote() ? $this->lockInvoice($document) : null;

            // The system sets the moment of issue: Colombian date and time with its offset, UTC instant.
            $now = Carbon::now();
            $local = $now->copy()->setTimezone(NumberTemplate::TIMEZONE);
            $today = $local->toDateString();

            $company = $this->currentCompany->company();
            $settings = DianSetting::current();
            $lines = $this->pricing->storedLines($document);
            $withholdings = $this->pricing->storedWithholdings($document);

            $this->validate($document, $invoice, $lines, $withholdings, $today, $settings);

            // Totals are recalculated on the server from the line copies, which were just checked
            // against the current taxes and withholdings. Nothing is written yet.
            $payable = $this->pricing->calculate($lines, $withholdings)->totals->payableAmount;

            if (! $payable->isPositive()) {
                throw ValidationException::withMessages(['lines' => [__('billing::errors.zero_total')]]);
            }

            if ($invoice !== null) {
                $this->balances->assertFits($document, $invoice, $payable);
            }

            // 3. The numbering, locked last. From here on every write is serialized by these locks.
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
                // A note keeps the customer exactly as its invoice froze it.
                'customer_snapshot' => $invoice->customer_snapshot ?? $this->snapshots->customer($document->contact ?? throw new LogicException('validate() requires the customer.')),
                'numbering_snapshot' => $this->snapshots->numbering($template),
                'balance_status' => $document->isNote() ? null : BalanceStatus::Open,
                // An invoice owes its amount due until notes and payments change it.
                'paid_amount' => '0.00',
                'receivable_amount' => $document->isNote() ? null : (string) $document->amount_due,
                'payment_status' => $document->isNote() ? null : InvoicePaymentStatus::Unpaid,
                // The invoice a note corrects, as its XML and PDF reference it.
                'related_snapshot' => $invoice === null ? null : [
                    'id' => $invoice->getKey(),
                    'full_number' => $invoice->full_number,
                    'cufe' => $invoice->cufe,
                    'issue_date' => ($invoice->issue_date ?? throw new LogicException('An issued invoice has a date of issue.'))->toDateString(),
                    'issue_time' => $invoice->issue_time,
                    'payable_amount' => (string) $invoice->payable_amount,
                ],
                // The chain starts: first run, no attempt yet.
                'electronic_status' => ElectronicStatus::Pending,
                'electronic_status_at' => $now,
                'electronic_run' => 1,
                'electronic_attempt' => 0,
            ]);

            // 4. CUFE or CUDE, software security code, QR URL and environment, from what was just frozen.
            $document->fill($this->stamp->stamp($document, $document->isNote() ? null : $template, $settings))->save();

            DocumentEvent::create([
                'document_id' => $document->id,
                'type' => DocumentEventType::Issued,
                'payload' => [
                    'number' => $number,
                    'full_number' => $document->full_number,
                    'cufe' => $document->cufe,
                    'related_number' => $invoice?->full_number,
                ],
                'occurred_at' => $now,
                'actor_id' => $actor?->getKey(),
            ]);

            $this->outbox->record($document->company_id, 'document', $document->id, ChainEvents::issued($document->type), [
                'document_id' => $document->id,
                'run' => 1,
                'type' => $document->type->value,
                'full_number' => $document->full_number,
                'cufe' => $document->cufe,
                'issue_date' => $today,
                'contact_id' => $document->contact_id,
                'related_document_id' => $document->related_document_id,
                'reason_code' => $document->reason_code,
                'payable_amount' => $document->payable_amount,
                'amount_due' => $document->amount_due,
                'currency' => $document->currency,
            ]);

            return $document;
        }, self::ATTEMPTS);

        // After the commit: the document is issued, and its chain starts from the outbox.
        $noun = $document->type->noun();
        Telemetry::record(
            LogEvent::DocumentIssued,
            sprintf('%s %s was issued.', preg_match('/^[aeiou]/', $noun) === 1 ? 'An' : 'A', $noun),
            outcome: 'success',
            durationMs: Telemetry::since($started),
            documentId: $document->id,
            detail: ['document_type' => $document->type->value],
        );

        return $document;
    }

    /**
     * @param  list<LineCopy>  $lines
     * @param  list<WithholdingCopy>  $withholdings
     */
    private function validate(Document $document, ?Document $invoice, array $lines, array $withholdings, string $today, DianSetting $settings): void
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if ($document->contact === null) {
            $errors['contact_id'][] = __('billing::errors.choose_customer');
        } elseif (! $document->isNote() && ! $document->contact->is_active) {
            $errors['contact_id'][] = __('billing::errors.inactive_customer');
        }

        if ($lines === []) {
            $errors['lines'][] = __('billing::errors.no_lines');
        }

        foreach ($lines as $index => $line) {
            if (blank($line['description'])) {
                $errors["lines.{$index}.description"][] = __('billing::errors.describe_line');
            }

            if (! BigDecimal::of($line['quantity'])->isPositive()) {
                $errors["lines.{$index}.quantity"][] = __('billing::errors.quantity');
            }

            if (BigDecimal::of($line['unit_price'])->isNegative()) {
                $errors["lines.{$index}.unit_price"][] = __('billing::errors.unit_price');
            }
        }

        if ($document->payment_form === null) {
            $errors['payment_form'][] = __('billing::errors.payment_form');
        }

        if ($document->payment_means_code === null) {
            $errors['payment_means_code'][] = __('billing::errors.payment_means');
        }

        // A note takes the payment terms of its invoice as they are.
        if (! $document->isNote() && $document->payment_form === PaymentForm::Credit) {
            if ($document->due_date === null) {
                $errors['due_date'][] = __('billing::errors.due_date_required');
            } elseif ($document->due_date->toDateString() < $today) {
                $errors['due_date'][] = __('billing::errors.due_date_past');
            }
        }

        if ($invoice !== null) {
            $errors = array_merge($errors, $this->noteProblems($document, $invoice));
        }

        $errors = array_merge(
            $errors,
            $this->staleRates($lines, $withholdings, $invoice),
            $this->requirements->missing(
                $this->currentCompany->company(),
                $settings,
                SigningCertificate::current(),
                // Only invoices need a resolution, its date and a technical key.
                $document->isNote() ? null : $this->numbering($document),
                $document->contact,
            ),
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A note corrects an invoice the local simulator accepted and nobody voided, with a concept of
     * its own type and a description of 20 to 5000 characters.
     *
     * @return array<string, list<string>>
     */
    private function noteProblems(Document $note, Document $invoice): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];

        if ($invoice->electronic_status !== ElectronicStatus::Accepted) {
            $errors['related_document_id'][] = __('billing::errors.invoice_not_accepted', ['number' => $invoice->full_number]);
        } elseif ($invoice->balance_status === BalanceStatus::Voided) {
            $errors['related_document_id'][] = __('billing::errors.invoice_voided', ['number' => $invoice->full_number]);
        }

        $concepts = $note->type === DocumentType::CreditNote ? CreditNoteConcept::class : DebitNoteConcept::class;

        if (blank($note->reason_code) || $concepts::tryFrom((string) $note->reason_code) === null) {
            $errors['reason_code'][] = __("billing::errors.choose_concept.{$note->type->value}");
        }

        $length = mb_strlen(trim((string) $note->reason_text));

        if ($length < self::REASON_MIN || $length > self::REASON_MAX) {
            $errors['reason_text'][] = __('billing::errors.describe_correction', ['min' => self::REASON_MIN, 'max' => self::REASON_MAX]);
        }

        return $errors;
    }

    /** The invoice a note corrects, locked until the transaction ends. */
    private function lockInvoice(Document $note): Document
    {
        $invoice = $note->related_document_id === null
            ? null
            : Document::query()->whereKey($note->related_document_id)->lockForUpdate()->first();

        if ($invoice === null || $invoice->type !== DocumentType::Invoice || $invoice->isDraft()) {
            throw ValidationException::withMessages(['related_document_id' => [__('billing::errors.choose_invoice')]]);
        }

        return $invoice;
    }

    /**
     * Taxes and withholdings copied into the draft that are no longer active, or whose rate
     * changed since the draft was saved. The draft is never recalculated in silence: the user
     * reviews it, and saving it again takes the current rates. A note keeps the rates frozen on its
     * invoice: a copy equal to the one of the invoice is never stale.
     *
     * @param  list<LineCopy>  $lines
     * @param  list<WithholdingCopy>  $withholdings
     * @return array<string, list<string>>
     */
    private function staleRates(array $lines, array $withholdings, ?Document $invoice = null): array
    {
        /** @var array<string, list<string>> $errors */
        $errors = [];
        $taxes = Tax::query()->whereKey(array_filter(array_column($lines, 'tax_id')))->get()->keyBy('id');
        $frozenTaxes = $invoice === null ? [] : $this->pricing->frozenTaxes($invoice);
        $frozenWithholdings = $invoice === null ? [] : $this->pricing->frozenWithholdings($invoice);

        foreach ($lines as $index => $line) {
            if ($line['tax_id'] === null) {
                continue;
            }

            $copy = $frozenTaxes[(string) $line['tax_id']] ?? null;

            if ($copy !== null && $copy['tax_code'] === (string) $line['tax_code'] && BigDecimal::of($copy['tax_rate'])->isEqualTo((string) $line['tax_rate'])) {
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

            $copy = $frozenWithholdings[(string) $withholding['withholding_id']] ?? null;

            if ($copy !== null && $copy['dian_code'] === (string) $withholding['dian_code'] && BigDecimal::of($copy['rate'])->isEqualTo((string) $withholding['rate'])) {
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
            return __('billing::errors.rate_inactive', ['name' => $name]);
        }

        if ($rate->dian_code->value !== $code || ! BigDecimal::of($rate->rate)->isEqualTo($copiedRate)) {
            return __('billing::errors.rate_changed', [
                'name' => $name,
                'from' => (string) BigDecimal::of($copiedRate)->strippedOfTrailingZeros(),
                'to' => (string) BigDecimal::of($rate->rate)->strippedOfTrailingZeros(),
            ]);
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
            ->where('document_type', $document->type)
            ->where('is_default', true)
            ->first(['id'])?->id;
    }

    private function lockNumbering(Document $document): NumberTemplate
    {
        $id = $this->numberingId($document);
        $type = $document->type->value;

        $template = $id === null ? null : NumberTemplate::query()->whereKey($id)->lockForUpdate()->first();

        if ($template === null) {
            throw ValidationException::withMessages(['number_template_id' => [__("billing::errors.numbering.choose.{$type}")]]);
        }

        $name = trim(__('billing::errors.numbering.range', ['prefix' => $template->prefix ?? '', 'from' => $template->from_number, 'to' => $template->to_number]));

        $problem = match (true) {
            $template->document_type !== NumberingType::forDocument($document->type) => __("billing::errors.numbering.wrong_type.{$type}"),
            ! $template->is_active => __('billing::errors.numbering.inactive', ['name' => $name]),
            default => match ($template->status()) {
                NumberingStatus::Exhausted => __('billing::errors.numbering.exhausted', ['name' => $name]),
                NumberingStatus::Expired => __('billing::errors.numbering.expired', ['name' => $name, 'date' => $template->valid_to?->format(__('formats.date')) ?? '']),
                NumberingStatus::Upcoming => __('billing::errors.numbering.upcoming', ['name' => $name, 'date' => $template->valid_from?->format(__('formats.date')) ?? '']),
                NumberingStatus::Active => null,
            },
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['number_template_id' => [$problem]]);
        }

        return $template;
    }
}
