<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member of the current company, as the team lists it. The id is the one of the membership.
 *
 * @mixin Membership
 */
final class TeamMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            // owner, admin, member or viewer.
            'role' => $this->role,
            'joined_at' => $this->created_at,
            'is_current_user' => $this->user_id === $request->user()?->getKey(),
        ];
    }
}
