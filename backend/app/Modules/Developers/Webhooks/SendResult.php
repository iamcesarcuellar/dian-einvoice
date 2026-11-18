<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Developers\Enums\WebhookAttemptOutcome;

/** What one attempt of a webhook got back. */
final readonly class SendResult
{
    public function __construct(
        public WebhookAttemptOutcome $outcome,
        public int $durationMs,
        public ?int $statusCode = null,
        public ?string $error = null,
        public ?string $snippet = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === WebhookAttemptOutcome::Succeeded;
    }
}
