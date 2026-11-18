<?php

namespace App\Modules\Reference\Http\Resources;

use App\Modules\Reference\Models\Municipality;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Municipality
 */
final class MunicipalityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'department_code' => $this->department_code,
            'department_name' => $this->whenLoaded('department', fn () => $this->department->name),
        ];
    }
}
