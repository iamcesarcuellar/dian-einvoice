<?php

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Models\DocumentTax;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentTax
 */
final class DocumentTaxResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dian_code' => $this->dian_code,
            'name' => $this->name,
            'rate' => $this->rate,
            'taxable_amount' => $this->taxable_amount,
            'tax_amount' => $this->tax_amount,
        ];
    }
}
