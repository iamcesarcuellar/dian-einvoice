<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Tax;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tax
 */
final class TaxResource extends JsonResource
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
            // Percentage as a string with 4 decimals, such as "19.0000".
            'rate' => $this->rate,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
