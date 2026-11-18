<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\DocumentLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentLine
 */
final class DocumentLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => $this->item
                ? ['id' => $this->item->id, 'code' => $this->item->code, 'name' => $this->item->name]
                : null),
            'code' => $this->code,
            'description' => $this->description,
            'unit_code' => $this->unit_code,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_rate' => $this->discount_rate,
            'discount_amount' => $this->discount_amount,
            'line_extension_amount' => $this->line_extension_amount,
            'tax_id' => $this->tax_id,
            'tax_code' => $this->tax_code,
            'tax_name' => $this->tax_name,
            'tax_rate' => $this->tax_rate,
            'tax_amount' => $this->tax_amount,
        ];
    }
}
