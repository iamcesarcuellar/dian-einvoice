<?php

namespace App\Modules\Dian\Http\Resources;

use App\Modules\Dian\Models\DianSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DianSetting
 */
final class DianSettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Always 2, habilitación (test): production is not part of this project.
            'environment' => $this->environment,
            'environment_name' => $this->environment->label(),
            'software_id' => $this->software_id,
            // The PIN is write-only: only whether one is stored.
            'software_pin_set' => filled($this->software_pin),
            'test_set_id' => $this->test_set_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
