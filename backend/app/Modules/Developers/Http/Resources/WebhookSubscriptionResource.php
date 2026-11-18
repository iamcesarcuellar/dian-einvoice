<?php

namespace App\Modules\Developers\Http\Resources;

use App\Modules\Developers\Models\WebhookSubscription;
use App\Modules\Developers\Webhooks\WebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A webhook subscription without its secret, which is shown only when created or rotated.
 *
 * @mixin WebhookSubscription
 */
final class WebhookSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'description' => $this->description,
            'events' => $this->events ?? [],
            'is_active' => $this->is_active,
            // active, inactive (switched off by hand) or disabled (switched off after failures in a row).
            'status' => $this->resource->status(),
            'disabled_at' => $this->disabled_at,
            'disabled_reason' => $this->disabled_reason,
            'consecutive_failures' => $this->consecutive_failures,
            'secret_hint' => WebhookSignature::hint($this->secret),
            'secret_rotated_at' => $this->secret_rotated_at,
            'last_delivery' => $this->last_delivery_at === null ? null : [
                'at' => $this->last_delivery_at,
                // succeeded or failed: the outcome of the last attempt.
                'status' => $this->last_delivery_status,
                'response_code' => $this->last_response_code,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
