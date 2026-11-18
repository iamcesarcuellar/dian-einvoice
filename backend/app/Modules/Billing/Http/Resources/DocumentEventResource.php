<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\DocumentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentEvent
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
            'actor' => $this->whenLoaded('actor', fn () => $this->actor
                ? ['id' => $this->actor->id, 'name' => $this->actor->name]
                : null),
        ];
    }
}
