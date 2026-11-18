<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Catalog\Http\Resources\ContactResource;
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
        $issuedAt = $this->issued_at;

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
            // Colombian date and time of issue; issued_at is the same instant in UTC.
            'issue_date' => $this->issue_date?->toDateString(),
            'issue_time' => $issuedAt?->copy()->setTimezone(NumberTemplate::TIMEZONE)->format('H:i:s'),
            'issued_at' => $issuedAt,
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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
