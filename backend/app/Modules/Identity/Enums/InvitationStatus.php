<?php

namespace App\Modules\Identity\Enums;

/**
 * State of an invitation to join a company, computed from its dates.
 */
enum InvitationStatus: string
{
    /** Sent and not answered: its link works until it expires. */
    case Pending = 'pending';

    /** Its link stopped working seven days after it was last sent; it can be sent again. */
    case Expired = 'expired';

    /** Canceled by an owner or admin, or replaced by a newer invitation to the same address. */
    case Canceled = 'canceled';

    /** Used: the person it was sent to is a member. */
    case Accepted = 'accepted';

    /** Not used and not canceled: listed in the team, and it can be sent again. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Expired;
    }
}
