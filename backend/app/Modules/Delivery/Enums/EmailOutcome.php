<?php

namespace App\Modules\Delivery\Enums;

/**
 * How one email attempt ended.
 */
enum EmailOutcome: string
{
    /** The mail server accepted the email. */
    case Sent = 'sent';

    /** The mail server could not be reached, timed out or answered a temporary error: retried. */
    case Failed = 'failed';

    /** The mail server refused the email for good (5xx), or the address is not valid: not retried. */
    case Rejected = 'rejected';

    /** The worker stopped during the call, so no outcome was recorded: retried. */
    case Abandoned = 'abandoned';

    public function isTransient(): bool
    {
        return $this === self::Failed || $this === self::Abandoned;
    }
}
