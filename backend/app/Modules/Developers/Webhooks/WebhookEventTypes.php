<?php

namespace App\Modules\Developers\Webhooks;

use App\Modules\Billing\BillingEvents;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Delivery\Pipeline\DeliveryEvents;
use App\Modules\Dian\Pipeline\ChainEvents;
use App\Modules\Payments\PaymentEvents;

/**
 * The public webhook events: stable names integrations subscribe to. They come from the outbox
 * events the modules already write, so a webhook exists if and only if its change was committed.
 */
final class WebhookEventTypes
{
    /** Sent by Send test event only, to one subscription, whatever events it lists. */
    public const string TEST = 'webhook.test';

    /** Public type => what happened. */
    public const array TYPES = [
        'invoice.issued' => 'An invoice was issued: it has its number and CUFE, and its electronic document chain started.',
        'invoice.accepted' => 'The DIAN (the local simulator) accepted the invoice.',
        'invoice.rejected' => 'The DIAN (the local simulator) rejected the invoice; its rules say why.',
        'invoice.failed' => 'The electronic document chain of the invoice failed after its retries (XML generation, signature or transmission).',
        'invoice.delivered' => 'The invoice was emailed to the customer with its AttachedDocument and PDF.',
        'invoice.paid' => 'Payments left nothing to collect on the invoice.',
        'invoice.voided' => 'An accepted credit note with the void concept voided the invoice.',
        'credit_note.issued' => 'A credit note was issued: it has its number and CUDE, and its chain started.',
        'credit_note.accepted' => 'The DIAN (the local simulator) accepted the credit note.',
        'credit_note.rejected' => 'The DIAN (the local simulator) rejected the credit note.',
        'credit_note.delivered' => 'The credit note was emailed to the customer.',
        'debit_note.issued' => 'A debit note was issued: it has its number and CUDE, and its chain started.',
        'debit_note.accepted' => 'The DIAN (the local simulator) accepted the debit note.',
        'debit_note.rejected' => 'The DIAN (the local simulator) rejected the debit note.',
        'debit_note.delivered' => 'The debit note was emailed to the customer.',
        'payment.recorded' => 'A payment was recorded and applied to invoices of its customer.',
        'payment.voided' => 'A payment was voided; its invoices got their balance back.',
    ];

    /** The outbox event types that may become a webhook. */
    public const array SOURCES = [
        ChainEvents::ISSUED,
        ChainEvents::CREDIT_NOTE_ISSUED,
        ChainEvents::DEBIT_NOTE_ISSUED,
        ChainEvents::ACCEPTED,
        ChainEvents::REJECTED,
        ChainEvents::FAILED,
        DeliveryEvents::DELIVERED,
        PaymentEvents::INVOICE_PAID,
        BillingEvents::INVOICE_VOIDED,
        PaymentEvents::RECORDED,
        PaymentEvents::VOIDED,
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * The public type of an outbox event, given the type of its document for the chain and delivery
     * events; null when it has no public event (the failure of a note, for one).
     */
    public static function publicType(string $outboxType, ?DocumentType $documentType = null): ?string
    {
        $prefix = $documentType?->value;

        $type = match ($outboxType) {
            ChainEvents::ACCEPTED => $prefix === null ? null : "{$prefix}.accepted",
            ChainEvents::REJECTED => $prefix === null ? null : "{$prefix}.rejected",
            ChainEvents::FAILED => $prefix === null ? null : "{$prefix}.failed",
            DeliveryEvents::DELIVERED => $prefix === null ? null : "{$prefix}.delivered",
            default => $outboxType,
        };

        return $type !== null && isset(self::TYPES[$type]) ? $type : null;
    }

    /** Whether the public event is about a payment; the others are about a document. */
    public static function isPaymentEvent(string $type): bool
    {
        return str_starts_with($type, 'payment.');
    }
}
