<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Item
 */
final class ItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'unit_code' => $this->unit_code,
            // String with 6 decimals, such as "450000.000000".
            'unit_price' => $this->unit_price,
            // Null means excluded: no tax.
            'tax_id' => $this->tax_id,
            'tax' => $this->whenLoaded('tax', fn () => $this->tax ? TaxResource::make($this->tax) : null),
            'standard_scheme' => $this->standard_scheme,
            'standard_code' => $this->standard_code,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
