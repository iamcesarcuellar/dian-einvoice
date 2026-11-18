<?php

namespace App\Modules\Delivery\Mail;

use App\Modules\Delivery\Enums\EmailOutcome;

/**
 * How one email attempt ended, as the mailer reported it.
 */
final readonly class SendResult
{
    public function __construct(
        public EmailOutcome $outcome,
        public int $durationMs,
        public ?string $messageId = null,
        public ?string $error = null,
    ) {}
}
