<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\XmlStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentPayment;
use App\Modules\Billing\Services\InvoiceBalance;
use App\Modules\Billing\Services\InvoiceReceivables;
use App\Modules\Catalog\Http\Resources\ContactResource;
use App\Modules\Delivery\Http\DeliveryPresenter;
use App\Modules\Dian\Http\Resources\DianTransmissionResource;
use App\Modules\Dian\Pipeline\ElectronicDocumentPipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /**
     * A row of a list: it leaves out the frozen copies of the company, the customer and the numbering,
     * and the nested contact, which only the detail shows.
     */
    private bool $summary = false;

    /**
     * The rows of a list, each one as a summary, so a page of 25 documents does not carry 75 frozen copies.
     */
    public static function summaries(mixed $resource): AnonymousResourceCollection
    {
        $collection = self::collection($resource);

        $collection->collection->each(function (self $row): void {
            $row->summary = true;
        });

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $xmlStatus = XmlStatus::of($this->resource);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'number_template_id' => $this->number_template_id,
            'prefix' => $this->prefix,
            'number' => $this->number,
            'full_number' => $this->full_number,
            'contact_id' => $this->contact_id,
            // On the detail only; a list row loads its contact just for the name of a draft.
            'contact' => $this->when(! $this->summary && $this->resource->relationLoaded('contact'), fn () => $this->contact ? ContactResource::make($this->contact) : null),
            // The customer as issued, or as it is now while the invoice is a draft.
            'customer_name' => $this->customer_snapshot['legal_name']
                ?? ($this->relationLoaded('contact') ? $this->contact?->legal_name : null),
            // Colombian date and time of issue, the time with its offset (10:53:10-05:00); issued_at
            // is the same instant in UTC.
            'issue_date' => $this->issue_date?->toDateString(),
            'issue_time' => $this->issue_time,
            'issued_at' => $this->issued_at,
            'due_date' => $this->due_date?->toDateString(),
            'payment_form' => $this->payment_form,
            'payment_means_code' => $this->payment_means_code,
            'currency' => $this->currency,
            'notes' => $this->notes,
            // A credit or debit note: the invoice it corrects, and the concept and description of the
            // correction (tables 13.2.4 and 13.2.5 of annex 1.9).
            'related_document_id' => $this->related_document_id,
            'related_document' => $this->relatedReference(),
            'reason_code' => $this->reason_code,
            'reason_text' => $this->reason_text,
            // An invoice: open, partially_credited or voided, from the notes the local simulator
            // accepted; null for drafts and notes.
            'balance_status' => $this->balance_status,
            ...$this->balanceDetails(),
            ...$this->receivable(),
            // Decimal strings with 2 decimals, calculated by the API.
            'totals' => $this->resource->only(Document::AMOUNTS),
            'taxes' => DocumentTaxResource::collection($this->whenLoaded('taxes')),
            'withholdings' => DocumentWithholdingResource::collection($this->whenLoaded('withholdings')),
            'lines' => DocumentLineResource::collection($this->whenLoaded('lines')),
            'events' => DocumentEventResource::collection($this->whenLoaded('events')),
            // The copies frozen on issue, on the detail only.
            'company_snapshot' => $this->when(! $this->summary, fn () => $this->company_snapshot),
            'customer_snapshot' => $this->when(! $this->summary, fn () => $this->customer_snapshot),
            'numbering_snapshot' => $this->when(! $this->summary, fn () => $this->numbering_snapshot),
            // Set on issue: the CUFE of an invoice or the CUDE of a note (key_name says which), the
            // DIAN verification URL drawn as the QR code and the environment (always 2, habilitación).
            'cufe' => $this->cufe,
            'key_name' => $this->keyName(),
            'qr_url' => $this->qr_url,
            'dian_environment' => $this->dian_environment,
            // UBL 2.1 XML: pending, generated (not signed yet), signed or failed; null for drafts.
            // Size and SHA-256 are those of the stored, signed XML.
            'xml' => [
                'status' => $xmlStatus,
                'available' => $xmlStatus?->available() === true,
                'file_name' => $this->full_number === null ? null : $this->full_number.'.xml',
                'size' => $this->xml_size,
                'sha256' => $this->xml_sha256,
                'generated_at' => $this->xml_generated_at,
                'signed_at' => $this->xml_signed_at,
                'certificate_serial' => $this->signing_certificate_serial,
            ],
            'electronic' => $this->electronic(),
            // Delivery to the customer once accepted: AttachedDocument, PDF and the email with both in
            // a zip, always caught by the local development mailbox. Files only on the detail.
            'delivery' => DeliveryPresenter::present($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Summary of an invoice, as a note shows the invoice it corrects.
     *
     * @return array<string, mixed>
     */
    public static function reference(Document $invoice): array
    {
        return [
            'id' => $invoice->getKey(),
            'type' => $invoice->type,
            'full_number' => $invoice->full_number,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'cufe' => $invoice->cufe,
            'customer_name' => $invoice->customer_snapshot['legal_name'] ?? null,
            'currency' => $invoice->currency,
            'payable_amount' => (string) $invoice->payable_amount,
            'electronic_status' => $invoice->electronic_status,
            'balance_status' => $invoice->balance_status,
        ];
    }

    /**
     * The invoice of a note: the loaded invoice, or the copy frozen on issue.
     *
     * @return array<string, mixed>|null
     */
    private function relatedReference(): ?array
    {
        if ($this->related_document_id === null) {
            return null;
        }

        if ($this->relationLoaded('relatedDocument') && $this->relatedDocument !== null) {
            return self::reference($this->relatedDocument);
        }

        $snapshot = $this->related_snapshot;

        return $snapshot === null ? ['id' => $this->related_document_id] : [
            'id' => $this->related_document_id,
            'type' => 'invoice',
            'full_number' => $snapshot['full_number'] ?? null,
            'issue_date' => $snapshot['issue_date'] ?? null,
            'cufe' => $snapshot['cufe'] ?? null,
            'customer_name' => $this->customer_snapshot['legal_name'] ?? null,
            'currency' => $this->currency,
            'payable_amount' => $snapshot['payable_amount'] ?? null,
        ];
    }

    /**
     * On the detail only. An issued invoice: its balance (total, credited, debited, balance and
     * what is left to credit, calculated by the server) and the summary of its notes. A note: the
     * balance of its invoice without the note itself, so its editor shows what is left to credit.
     *
     * @return array<string, mixed>
     */
    private function balanceDetails(): array
    {
        $balances = app(InvoiceBalance::class);

        if (! $this->isNote() && ! $this->isDraft() && $this->relationLoaded('relatedNotes')) {
            return [
                'balance' => $balances->of($this->resource, null, $this->relatedNotes),
                'related_notes' => $this->relatedNotes->map(fn (Document $note): array => [
                    'id' => $note->getKey(),
                    'type' => $note->type,
                    'status' => $note->status,
                    'full_number' => $note->full_number,
                    'issue_date' => $note->issue_date?->toDateString(),
                    'reason_code' => $note->reason_code,
                    'payable_amount' => (string) $note->payable_amount,
                    'currency' => $note->currency,
                    'electronic_status' => $note->electronic_status,
                    'created_at' => $note->created_at,
                ])->values()->all(),
            ];
        }

        if ($this->isNote() && $this->relationLoaded('lines') && $this->relationLoaded('relatedDocument') && $this->relatedDocument !== null) {
            return ['invoice_balance' => $balances->of($this->relatedDocument, $this->getKey())];
        }

        return [];
    }

    /**
     * An issued invoice: what payments took, what is left to collect after its accepted notes and
     * those payments (negative: a credit in favor of the customer), its payment status, whether it
     * is overdue, and on the detail the payments applied to it. Null for drafts and notes.
     *
     * @return array<string, mixed>
     */
    private function receivable(): array
    {
        $issuedInvoice = ! $this->isNote() && ! $this->isDraft();

        return [
            'paid_amount' => $issuedInvoice ? (string) $this->paid_amount : null,
            'receivable_amount' => $issuedInvoice && $this->receivable_amount !== null ? (string) $this->receivable_amount : null,
            'payment_status' => $issuedInvoice ? $this->payment_status : null,
            'overdue' => $issuedInvoice && InvoiceReceivables::overdue($this->resource),
            'days_past_due' => $issuedInvoice ? max(0, InvoiceReceivables::daysPastDue($this->resource)) : null,
            'payments' => $this->when($issuedInvoice && $this->relationLoaded('payments'), fn () => $this->payments->map(fn (DocumentPayment $payment): array => [
                'payment_id' => $payment->payment_id,
                'number' => $payment->payment_number,
                'payment_date' => $payment->payment_date->toDateString(),
                'payment_means_code' => $payment->payment_means_code,
                'amount' => (string) $payment->amount,
                // applied, or released when the payment was voided.
                'status' => $payment->status,
            ])->values()->all()),
        ];
    }

    /**
     * The electronic document chain; null for drafts. Every result comes from the local DIAN
     * simulator (validated_by): no document of this project is registered with the DIAN.
     *
     * @return array<string, mixed>|null
     */
    private function electronic(): ?array
    {
        $status = $this->electronic_status;

        if ($status === null) {
            return null;
        }

        $pipeline = app(ElectronicDocumentPipeline::class);
        $waiting = $status === ElectronicStatus::Sending && $this->electronic_next_attempt_at !== null;
        $messages = $this->dian_messages ?? [];

        return [
            // pending, generated, signed, sending, accepted, rejected or failed.
            'status' => $status,
            'final' => $status->isFinal(),
            'status_changed_at' => $this->electronic_status_at,
            // Pass of the chain: 1 on issue, one more on each Resend or Retry after a failure.
            'run' => $this->electronic_run,
            // Transmission attempt of this run: the current one, or the last that failed while waiting.
            'attempt' => $this->electronic_attempt,
            'max_attempts' => $pipeline->maxAttempts(),
            // Set while waiting to retry after a transient failure.
            'retrying' => $waiting,
            'next_attempt_at' => $this->electronic_next_attempt_at,
            'failed_step' => $this->electronic_failed_step,
            'can_retry' => $waiting || $status === ElectronicStatus::Failed,
            'can_resend' => $status === ElectronicStatus::Rejected,
            'zip_file_name' => $this->dian_zip_file_name,
            'xml_file_name' => $this->dian_xml_file_name,
            // Who validates: always the local simulator, never the DIAN.
            'validated_by' => 'local_simulator',
            // The last answer of the local simulator; null until it answers.
            'result' => $this->dian_status_code === null ? null : [
                'gateway' => $this->dian_gateway,
                'accepted' => $status === ElectronicStatus::Accepted,
                'status_code' => $this->dian_status_code,
                'status_description' => $this->dian_status_description,
                'status_message' => $this->dian_status_message,
                'rules' => array_values(array_filter($messages, fn (array $message): bool => ($message['kind'] ?? null) === 'rejection')),
                'notifications' => array_values(array_filter($messages, fn (array $message): bool => ($message['kind'] ?? null) === 'notification')),
                'responded_at' => $this->dian_responded_at,
                'response_available' => $this->dian_response_path !== null,
                'response_file_name' => $this->full_number === null ? null : $this->full_number.'-simulator-response.xml',
            ],
            'transmissions' => DianTransmissionResource::collection($this->whenLoaded('transmissions')),
        ];
    }
}
