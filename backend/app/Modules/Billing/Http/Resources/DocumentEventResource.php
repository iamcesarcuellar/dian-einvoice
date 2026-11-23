<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\DocumentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentEvent
 *
 * @property DocumentEvent $resource
 */
final class DocumentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
            'occurred_at' => $this->occurred_at,
            // A user, or the API key that acted (type api_key, with its name); null for the system.
            'actor' => $this->when(
                // Not whenLoaded('actor'): that returns null without asking when the user is null.
                $this->resource->relationLoaded('actor'),
                fn () => $this->resource->actorSummary(),
            ),
        ];
    }
}
