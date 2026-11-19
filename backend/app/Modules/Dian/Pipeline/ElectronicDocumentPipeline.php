<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\DocumentEvent;
use App\Modules\Identity\Models\User;
use App\Support\Outbox\Outbox;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Building blocks every step of the electronic document chain uses inside its transaction: lock
 * the document, move it through the state machine, write the timeline and record the event of the
 * next step in the outbox, plus the retry policy of the transmission.
 */
final class ElectronicDocumentPipeline
{
    public function __construct(private readonly Outbox $outbox) {}

    /** The document of the current company, locked until the transaction ends; null if it is gone. */
    public function lock(string $documentId): ?Document
    {
        return Document::query()->whereKey($documentId)->lockForUpdate()->first();
    }

    /**
     * Moves the document to $state, which the state machine must allow, and saves it with the
     * other attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function move(Document $document, ElectronicStatus $state, array $attributes = []): void
    {
        $current = $document->electronic_status;

        if ($current === null || ! $current->canMoveTo($state)) {
            throw new LogicException(sprintf('The electronic document %s cannot move from %s to %s.', $document->id, $current->value ?? 'none', $state->value));
        }

        $document->fill([...$attributes, 'electronic_status' => $state, 'electronic_status_at' => Carbon::now()])->save();
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function timeline(Document $document, DocumentEventType $type, ?array $payload = null, ?User $actor = null): void
    {
        DocumentEvent::create([
            'document_id' => $document->getKey(),
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => Carbon::now(),
            'actor_id' => $actor?->getKey(),
        ]);
    }

    /**
     * Records an event of the document in the outbox, with its id and its run.
     *
     * @param  array<string, mixed>  $payload
     */
    public function emit(Document $document, string $type, array $payload = [], ?DateTimeInterface $availableAt = null): void
    {
        $this->outbox->record($document->company_id, 'document', $document->id, $type, [
            'document_id' => $document->getKey(),
            'run' => $document->electronic_run,
            ...$payload,
        ], $availableAt);
    }

    /**
     * Ends the chain as failed at a step, with the timeline entry and the integration event.
     *
     * @param  'generate'|'sign'|'transmit'  $step
     */
    public function fail(Document $document, string $step, string $reason, int $attempts = 0): void
    {
        $reason = Str::limit($reason, 497);

        $this->move($document, ElectronicStatus::Failed, ['electronic_failed_step' => $step, 'electronic_next_attempt_at' => null]);

        if ($step === 'transmit') {
            $this->timeline($document, DocumentEventType::TransmissionFailed, ['attempts' => $attempts, 'reason' => $reason]);
        } else {
            $this->timeline($document, DocumentEventType::XmlFailed, ['step' => $step, 'reason' => $reason]);
        }

        $this->emit($document, ChainEvents::FAILED, ['step' => $step, 'reason' => $reason]);
    }

    /** Attempts of one transmission: the first plus one per retry delay. */
    public function maxAttempts(): int
    {
        return 1 + count($this->delays());
    }

    /**
     * Seconds to wait after attempt $attempt failed: the configured delay for it plus a random share
     * of up to the jitter, so documents that failed together do not all retry at the same moment.
     */
    public function retryDelay(int $attempt): int
    {
        $delays = $this->delays();
        $base = $delays[max(0, min($attempt, count($delays)) - 1)] ?? 2;
        $jitter = max(0.0, (float) config('dian.retry_jitter', 0.2));

        return max(1, (int) round($base * (1 + $jitter * mt_rand() / mt_getrandmax())));
    }

    /**
     * Seconds a call may still be running: the connection and answer timeouts plus a margin. A call
     * older than this that never finished belongs to a worker that stopped.
     */
    public function lease(): int
    {
        return (int) ceil((float) config('dian.connect_timeout', 2) + (float) config('dian.timeout', 8)) + 5;
    }

    /**
     * @return list<int>
     */
    private function delays(): array
    {
        $delays = config('dian.retry_delays', []);

        return $delays !== [] ? $delays : [2, 5, 15, 45, 120];
    }
}
