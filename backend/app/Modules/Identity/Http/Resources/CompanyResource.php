<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Enums\Permission;
use App\Modules\Identity\Models\Company;
use App\Modules\Reference\Http\Resources\MunicipalityResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Company
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'identification_number' => $this->identification_number,
            'check_digit' => $this->check_digit,
            'organization_type' => $this->organization_type,
            'tax_scheme' => $this->tax_scheme,
            'fiscal_responsibilities' => $this->fiscal_responsibilities ?? [],
            'registration_number' => $this->registration_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'country_code' => Company::COUNTRY_CODE,
            'municipality_code' => $this->municipality_code,
            'municipality' => $this->whenLoaded('municipality', fn () => $this->municipality
                ? MunicipalityResource::make($this->municipality)
                : null),
            'postal_code' => $this->postal_code,
            // Role of the signed-in user in this company (owner, admin, member or viewer) and what it allows.
            'role' => $this->whenPivotLoadedAs('membership', 'memberships', fn () => $this->membership->role),
            'permissions' => $this->whenPivotLoadedAs('membership', 'memberships', fn () => array_map(
                fn (Permission $permission): string => $permission->value,
                $this->membership->role->permissions(),
            )),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
