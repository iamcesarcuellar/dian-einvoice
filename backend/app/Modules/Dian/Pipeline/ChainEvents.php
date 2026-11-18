<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentType;

/**
 * Outbox event types of the electronic document chain. Each step writes, in the transaction of its
 * own result, the event that starts the next step; the relay turns it into a queued job.
 */
final class ChainEvents
{
    /** Written by the issue of an invoice; starts the generation of the XML. */
    public const string ISSUED = 'invoice.issued';

    /** Written by the issue of a credit or a debit note; they start the same chain. */
    public const string CREDIT_NOTE_ISSUED = 'credit_note.issued';

    public const string DEBIT_NOTE_ISSUED = 'debit_note.issued';

    /** Generate the XML again: Resend, or Retry now after the generation failed. */
    public const string GENERATE = 'electronic_document.generate_due';

    public const string SIGN = 'electronic_document.sign_due';

    /** Payload attempt: the transmission attempt to start; retries carry available_at. */
    public const string TRANSMIT = 'electronic_document.transmit_due';

    /** Payload transmission_id: the call whose answer is recorded. */
    public const string RESPONSE_RECEIVED = 'electronic_document.response_received';

    /** Final results, for integrations (webhooks arrive in stage 10). */
    public const string ACCEPTED = 'electronic_document.accepted';

    public const string REJECTED = 'electronic_document.rejected';

    public const string FAILED = 'electronic_document.failed';

    /** DIAN settings or numbering changed: register the company with the simulator again. */
    public const string TAXPAYER_CHANGED = 'dian.taxpayer_changed';

    /** The event the issue of a document writes. */
    public static function issued(DocumentType $type): string
    {
        return match ($type) {
            DocumentType::Invoice => self::ISSUED,
            DocumentType::CreditNote => self::CREDIT_NOTE_ISSUED,
            DocumentType::DebitNote => self::DEBIT_NOTE_ISSUED,
        };
    }
}
