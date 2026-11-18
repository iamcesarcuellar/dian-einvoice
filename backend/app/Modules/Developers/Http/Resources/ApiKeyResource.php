<?php

namespace App\Modules\Developers\Http\Resources;

use App\Modules\Developers\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An API key without its secret: the key itself is shown once, when it is created.
 *
 * @mixin ApiKey
 */
final class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // read_only or read_write.
            'level' => $this->level,
            // The prefix and the public part of the key, such as dei_k3J9xQ2mPa7Z: enough to recognize it.
            'visible_id' => $this->resource->visibleId(),
            // active, expired or revoked.
            'status' => $this->resource->status(),
            'rate_limit_per_minute' => $this->resource->rateLimit(),
            'custom_rate_limit' => $this->rate_limit_per_minute !== null,
            'expires_at' => $this->expires_at,
            // Updated at most once a minute.
            'last_used_at' => $this->last_used_at,
            'revoked_at' => $this->revoked_at,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name]),
            'created_at' => $this->created_at,
        ];
    }
}
