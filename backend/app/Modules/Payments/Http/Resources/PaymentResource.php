<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentAllocation;
use App\Modules\Payments\Models\PaymentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'full_number' => $this->full_number,
            'payment_date' => $this->payment_date->toDateString(),
            'contact_id' => $this->contact_id,
            'customer_name' => $this->customer_name,
            'customer_identification' => $this->customer_identification,
            // Decimal string with 2 decimals: the sum applied to its invoices.
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'payment_means_code' => $this->payment_means_code,
            'reference' => $this->reference,
            'notes' => $this->notes,
            // recorded or voided; a payment is never edited or deleted.
            'status' => $this->status,
            'void_reason' => $this->void_reason,
            'voided_at' => $this->voided_at,
            'voided_by' => $this->whenLoaded('voider', fn () => $this->voider === null ? null : ['id' => $this->voider->id, 'name' => $this->voider->name]),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn (PaymentAllocation $allocation): array => [
                'id' => $allocation->id,
                'document_id' => $allocation->document_id,
                'document_number' => $allocation->document_number,
                'document_issue_date' => $allocation->document_issue_date?->toDateString(),
                'document_due_date' => $allocation->document_due_date?->toDateString(),
                'amount' => (string) $allocation->amount,
                'balance_after' => (string) $allocation->balance_after,
            ])->values()->all()),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn (PaymentEvent $event): array => [
                'id' => $event->id,
                'type' => $event->type,
                'payload' => $event->payload,
                'occurred_at' => $event->occurred_at,
                // A user, or the API key that acted (type api_key, with its name); null for the system.
                'actor' => $event->actorSummary(),
            ])->values()->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
