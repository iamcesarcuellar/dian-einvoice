<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\XmlStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Catalog\Http\Resources\ContactResource;
use App\Modules\Delivery\Http\DeliveryPresenter;
use App\Modules\Dian\Http\Resources\DianTransmissionResource;
use App\Modules\Dian\Pipeline\ElectronicDocumentPipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
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
            'contact' => $this->whenLoaded('contact', fn () => $this->contact ? ContactResource::make($this->contact) : null),
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
            // Decimal strings with 2 decimals, calculated by the API.
            'totals' => $this->resource->only(Document::AMOUNTS),
            'taxes' => DocumentTaxResource::collection($this->whenLoaded('taxes')),
            'withholdings' => DocumentWithholdingResource::collection($this->whenLoaded('withholdings')),
            'lines' => DocumentLineResource::collection($this->whenLoaded('lines')),
            'events' => DocumentEventResource::collection($this->whenLoaded('events')),
            'company_snapshot' => $this->company_snapshot,
            'customer_snapshot' => $this->customer_snapshot,
            'numbering_snapshot' => $this->numbering_snapshot,
            // Set on issue: the CUFE, the DIAN verification URL drawn as the QR code and the
            // environment (always 2, habilitación).
            'cufe' => $this->cufe,
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
