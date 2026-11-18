<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Pipeline\ElectronicDocumentPipeline;
use App\Modules\Identity\Models\User;
use App\Support\Outbox\Outbox;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Building blocks every step of the customer delivery uses inside its transaction: lock the
 * document, move its delivery state, write the timeline and record the event of the next step in
 * the outbox. Email retries use the waits of the DIAN transmission (DIAN_RETRY_DELAYS).
 */
final class DeliveryPipeline
{
    public function __construct(
        private readonly ElectronicDocumentPipeline $chain,
        private readonly Outbox $outbox,
    ) {}

    /** The document of the current company, locked until the transaction ends; null if it is gone. */
    public function lock(string $documentId): ?Document
    {
        return $this->chain->lock($documentId);
    }

    /**
     * Starts the delivery of a document the local simulator has just accepted, inside the transaction
     * that records the acceptance: run 1, from the AttachedDocument, to the email frozen on issue.
     */
    public function start(Document $document): void
    {
        $this->begin($document, DeliveryStep::AttachedDocument, 1, self::defaultRecipient($document));
    }

    /** A run of the delivery from $step, for $recipient (null: the customer has no email). */
    public function begin(Document $document, DeliveryStep $step, int $run, ?string $recipient): void
    {
        $document->fill([
            'delivery_status' => DeliveryStatus::Pending,
            'delivery_step' => $step,
            'delivery_status_at' => Carbon::now(),
            'delivery_run' => $run,
            'delivery_attempt' => 0,
            'delivery_next_attempt_at' => null,
            'delivery_recipient' => $recipient,
            'delivery_error' => null,
        ])->save();

        $this->emit($document, self::eventFor($step), $step === DeliveryStep::Email ? ['attempt' => 1] : []);
    }

    /**
     * The next step is due.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function advance(Document $document, DeliveryStep $step, array $attributes = []): void
    {
        $document->fill([...$attributes, 'delivery_step' => $step])->save();
    }

    /**
     * The delivery ended: delivered, or not applicable when the customer has no email.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finish(Document $document, DeliveryStatus $status, array $attributes = []): void
    {
        $document->fill([
            ...$attributes,
            'delivery_status' => $status,
            'delivery_status_at' => Carbon::now(),
            'delivery_step' => null,
            'delivery_next_attempt_at' => null,
            'delivery_error' => null,
        ])->save();
    }

    /** Ends the delivery as failed at a step, with the timeline entry and the integration event. */
    public function fail(Document $document, DeliveryStep $step, string $reason, int $attempts = 0): void
    {
        $reason = Str::limit($reason, 497);

        $document->fill([
            'delivery_status' => DeliveryStatus::Failed,
            'delivery_status_at' => Carbon::now(),
            'delivery_step' => $step,
            'delivery_next_attempt_at' => null,
            'delivery_error' => $reason,
        ])->save();

        $this->timeline($document, DocumentEventType::DeliveryFailed, [
            'run' => $document->delivery_run,
            'step' => $step->value,
            'reason' => $reason,
            'attempts' => $attempts,
        ]);
        $this->emit($document, DeliveryEvents::FAILED, ['full_number' => $document->full_number, 'step' => $step->value, 'reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function timeline(Document $document, DocumentEventType $type, ?array $payload = null, ?User $actor = null): void
    {
        $this->chain->timeline($document, $type, $payload, $actor);
    }

    /**
     * Records an event of the delivery in the outbox, with the document id and its delivery run.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(Document $document, string $type, array $payload = [], ?DateTimeInterface $availableAt = null): void
    {
        $this->outbox->record($document->company_id, 'document', $document->getKey(), $type, [
            'document_id' => $document->getKey(),
            'delivery_run' => $document->delivery_run,
            ...$payload,
        ], $availableAt);
    }

    /** The event that starts a step. */
    public static function eventFor(DeliveryStep $step): string
    {
        return match ($step) {
            DeliveryStep::AttachedDocument => DeliveryEvents::ATTACHED_DOCUMENT_DUE,
            DeliveryStep::Pdf => DeliveryEvents::PDF_DUE,
            DeliveryStep::Email => DeliveryEvents::EMAIL_DUE,
        };
    }

    /** Attempts of one email: the same as a DIAN transmission, the first plus one per retry delay. */
    public function maxAttempts(): int
    {
        return $this->chain->maxAttempts();
    }

    /** Seconds to wait after attempt $attempt failed: the waits of the transmission, with jitter. */
    public function retryDelay(int $attempt): int
    {
        return $this->chain->retryDelay($attempt);
    }

    /**
     * Seconds an email attempt may still be running: twice the mail timeout (connection and answer)
     * plus a margin. An attempt older than this that never finished belongs to a worker that stopped.
     */
    public function lease(): int
    {
        return (int) ceil(2 * (float) config('mail.mailers.smtp.timeout', 10)) + 5;
    }

    /** The email of the customer as frozen on issue, when it is a valid address. */
    public static function defaultRecipient(Document $document): ?string
    {
        $email = trim((string) ($document->customer_snapshot['email'] ?? ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
