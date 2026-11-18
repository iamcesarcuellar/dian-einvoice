<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\DocumentWithholding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentWithholding
 */
final class DocumentWithholdingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'withholding_id' => $this->withholding_id,
            'dian_code' => $this->dian_code,
            'name' => $this->name,
            'rate' => $this->rate,
            'base_amount' => $this->base_amount,
            'amount' => $this->amount,
        ];
    }
}
