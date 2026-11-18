<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\NumberTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NumberTemplate
 */
final class NumberTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $technicalKey = $this->technical_key;

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'prefix' => $this->prefix,
            'from_number' => $this->from_number,
            'to_number' => $this->to_number,
            'next_number' => $this->next_number,
            'resolution_number' => $this->resolution_number,
            'valid_from' => $this->valid_from?->toDateString(),
            'valid_to' => $this->valid_to?->toDateString(),
            // The technical key is write-only: only whether it is set and its last four characters.
            'technical_key_set' => filled($technicalKey),
            'technical_key_last4' => filled($technicalKey) ? substr($technicalKey, -4) : null,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            // active, upcoming, expired or exhausted.
            'status' => $this->status(),
            'usage' => [
                'used' => $this->usedCount(),
                'total' => $this->totalCount(),
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
