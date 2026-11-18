<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Withholding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Withholding
 */
final class WithholdingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dian_code' => $this->dian_code,
            'name' => $this->name,
            // Percentage as a string with 4 decimals, such as "2.5000".
            'rate' => $this->rate,
            // What it is calculated on: "tax" (the IVA) or "subtotal" (before taxes).
            'base' => $this->base(),
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
