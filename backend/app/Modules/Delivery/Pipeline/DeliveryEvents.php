<?php

namespace App\Modules\Delivery\Pipeline;

/**
 * Outbox event types of the customer delivery. Each step writes, in the transaction of its own
 * result, the event that starts the next step; the relay turns it into a queued job. Payloads carry
 * the document_id and its delivery_run.
 */
final class DeliveryEvents
{
    /** Written with the acceptance, and by Send again or Regenerate PDF when it must be built again. */
    public const string ATTACHED_DOCUMENT_DUE = 'delivery.attached_document_due';

    public const string PDF_DUE = 'delivery.pdf_due';

    /** Payload attempt: the email attempt to start; retries carry available_at. */
    public const string EMAIL_DUE = 'delivery.email_due';

    /** Final results, for integrations (webhooks arrive in stage 10). */
    public const string DELIVERED = 'delivery.delivered';

    public const string NOT_APPLICABLE = 'delivery.not_applicable';

    public const string FAILED = 'delivery.failed';
}
