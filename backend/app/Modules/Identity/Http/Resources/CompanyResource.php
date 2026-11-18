<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Company;
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
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            // Role of the signed-in user in this company: owner, admin, member or viewer.
            'role' => $this->whenPivotLoadedAs('membership', 'memberships', fn () => $this->membership->role),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
