<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\TeamInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation of the current company, as the team lists it. Its token is never returned: it
 * exists only in the email link.
 *
 * @mixin TeamInvitation
 */
final class TeamInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $inviter = $this->resource->relationLoaded('inviter') ? $this->inviter : null;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            // pending or expired in the list; accepted and canceled ones are not listed.
            'status' => $this->status(),
            'invited_by' => $inviter === null ? null : ['id' => $inviter->id, 'name' => $inviter->name],
            'expires_at' => $this->expires_at,
            // When the mail server last took its email; null when it never did.
            'last_sent_at' => $this->last_sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
