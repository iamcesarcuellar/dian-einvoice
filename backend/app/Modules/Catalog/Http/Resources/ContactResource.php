<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Models\Contact;
use App\Modules\Reference\Http\Resources\MunicipalityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Contact
 */
final class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_type' => $this->organization_type,
            'identification_type' => $this->identification_type,
            'identification_number' => $this->identification_number,
            'check_digit' => $this->check_digit,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'tax_scheme' => $this->tax_scheme,
            'fiscal_responsibilities' => $this->fiscal_responsibilities,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'country_code' => $this->country_code,
            'municipality_code' => $this->municipality_code,
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality
                ? MunicipalityResource::make($this->municipality)
                : null),
            'postal_code' => $this->postal_code,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
