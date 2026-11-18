<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\EmailOutcome;
use App\Modules\Delivery\Mail\DeliveryMailer;
use App\Modules\Delivery\Mail\DeliverySubject;
use App\Modules\Delivery\Mail\SendResult;
use App\Modules\Delivery\Models\DeliveryAttempt;
use App\Modules\Delivery\Services\DeliveryPackages;
use App\Modules\Dian\Pipeline\ChainFailure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivery step 3: packages the AttachedDocument and the PDF in one zip and emails it to the
 * customer, one attempt per job, as the transmission does:
 *
 * 1. Claim, in a transaction: lock the document and check the attempt the event asks for is the
 *    next one, or one whose worker stopped during its call (never finished, older than the lease).
 *    The zip is built. A customer without email ends the delivery here as not applicable. The
 *    attempt is written to delivery_attempts before the mailer is called.
 * 2. Send, outside any transaction, through Laravel's mailer (SMTP to the local mailbox).
 * 3. Record, in a transaction: the outcome, its timeline entry and the next event. Sent: delivered.
 *    A transient failure schedules the next attempt with the waits of the transmission; after the
 *    last one, or a refusal for good, the delivery fails and the job goes to the dead letter queue.
 */
final class SendDeliveryEmail extends DeliveryJob
{
    public function step(): DeliveryStep
    {
        return DeliveryStep::Email;
    }

    public function process(DeliveryPipeline $pipeline, DeliveryPackages $packages, DeliveryMailer $mailer): void
    {
        /** @var array{0: DeliveryAttempt|null, 1: ChainFailure|null} $claim */
        $claim = DB::transaction(fn (): array => $this->claim($pipeline, $packages), 3);
        [$attempt, $failure] = $claim;

        if ($attempt !== null) {
            $result = $mailer->send($attempt);
            $failure = DB::transaction(fn (): ?ChainFailure => $this->record($attempt, $result, $pipeline), 3);
        }

        if ($failure !== null) {
            $this->deadLetter($failure);
        }
    }

    /**
     * @return array{0: DeliveryAttempt|null, 1: ChainFailure|null} The attempt to send now, and the failure that ended the delivery.
     */
    private function claim(DeliveryPipeline $pipeline, DeliveryPackages $packages): array
    {
        $document = $pipeline->lock($this->documentId);

        if ($document === null || ! $this->expects($document)) {
            return [null, null];
        }

        $running = $this->unfinished($document);

        if ($document->delivery_attempt === $this->attempt - 1 && $running === null) {
            // The next attempt.
        } elseif ($document->delivery_attempt === $this->attempt && $running !== null) {
            // The worker of this attempt stopped during its call: take over once it cannot be running.
            if ($running->started_at->gt(Carbon::now()->subSeconds($pipeline->lease()))) {
                return [null, null];
            }

            $running->fill([
                'finished_at' => Carbon::now(),
                'outcome' => EmailOutcome::Abandoned,
                'error' => 'No outcome was recorded: the worker stopped during the call.',
            ])->save();

            $pipeline->timeline($document, DocumentEventType::EmailAttempt, $this->payload($running, $pipeline));
        } else {
            return [null, null];
        }

        // Built from the stored AttachedDocument and PDF on every attempt, so it always holds the current ones.
        try {
            $package = $packages->build($document);
        } catch (Throwable $exception) {
            report($exception);
            $pipeline->fail($document, DeliveryStep::Email, 'The zip could not be built: '.$exception->getMessage());

            return [null, new ChainFailure("The zip of {$document->full_number} could not be built.", previous: $exception)];
        }

        // The final consumer and any customer without an email: the files exist, nothing is sent.
        if ($document->delivery_recipient === null) {
            $pipeline->finish($document, DeliveryStatus::NotApplicable, ['delivery_attempt' => 0]);
            $pipeline->timeline($document, DocumentEventType::DeliveryNotApplicable, ['run' => $document->delivery_run, 'package' => $package->file_name]);
            $pipeline->emit($document, DeliveryEvents::NOT_APPLICABLE, ['full_number' => $document->full_number]);

            return [null, null];
        }

        $document->fill(['delivery_attempt' => $this->attempt, 'delivery_next_attempt_at' => null])->save();

        return [DeliveryAttempt::create([
            'document_id' => $document->getKey(),
            'run' => $document->delivery_run,
            'attempt' => $this->attempt,
            'recipient' => $document->delivery_recipient,
            'subject' => DeliverySubject::for($document),
            'package_file_name' => $package->file_name,
            'package_sha256' => $package->sha256,
            'mailer' => (string) config('mail.default'),
            'started_at' => Carbon::now(),
        ]), null];
    }

    private function record(DeliveryAttempt $attempt, SendResult $result, DeliveryPipeline $pipeline): ?ChainFailure
    {
        $document = $pipeline->lock($attempt->document_id);
        $row = DeliveryAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();

        // Another worker took this attempt over as abandoned: its own call decides.
        if ($document === null || $row === null || $row->finished_at !== null) {
            return null;
        }

        $row->fill([
            'finished_at' => Carbon::now(),
            'duration_ms' => $result->durationMs,
            'outcome' => $result->outcome,
            'message_id' => $result->messageId,
            'error' => $result->error,
        ])->save();

        // Send again moved the delivery on meanwhile.
        if ($document->delivery_status !== DeliveryStatus::Pending
            || $document->delivery_step !== DeliveryStep::Email
            || $document->delivery_run !== $row->run
            || $document->delivery_attempt !== $row->attempt) {
            return null;
        }

        $payload = $this->payload($row, $pipeline);

        if ($result->outcome === EmailOutcome::Sent) {
            $pipeline->finish($document, DeliveryStatus::Delivered, ['delivered_at' => $row->finished_at]);
            $pipeline->timeline($document, DocumentEventType::EmailAttempt, $payload);
            $pipeline->emit($document, DeliveryEvents::DELIVERED, [
                'full_number' => $document->full_number,
                'recipient' => $row->recipient,
                'message_id' => $row->message_id,
            ]);

            return null;
        }

        if ($result->outcome->isTransient() && $row->attempt < $pipeline->maxAttempts()) {
            $delay = $pipeline->retryDelay($row->attempt);
            $next = Carbon::now()->addSeconds($delay);

            $document->fill(['delivery_next_attempt_at' => $next])->save();
            $pipeline->timeline($document, DocumentEventType::EmailAttempt, [...$payload, 'retry_in' => $delay, 'next_attempt_at' => $next->toIso8601String()]);
            $pipeline->emit($document, DeliveryEvents::EMAIL_DUE, ['attempt' => $row->attempt + 1], $next);

            return null;
        }

        $reason = sprintf('Attempt %d of %d: %s', $row->attempt, $pipeline->maxAttempts(), $row->error ?? $row->outcome?->value ?? 'no outcome');

        $pipeline->timeline($document, DocumentEventType::EmailAttempt, $payload);
        $pipeline->fail($document, DeliveryStep::Email, $reason, $row->attempt);

        return new ChainFailure("The email of {$document->full_number} could not be delivered. {$reason}");
    }

    /** The attempt of the current run that never finished, if any. */
    private function unfinished(Document $document): ?DeliveryAttempt
    {
        return DeliveryAttempt::query()
            ->where('document_id', $document->getKey())
            ->where('run', $document->delivery_run)
            ->whereNull('finished_at')
            ->latest('started_at')
            ->first();
    }

    /**
     * The timeline entry of one attempt.
     *
     * @return array<string, mixed>
     */
    private function payload(DeliveryAttempt $row, DeliveryPipeline $pipeline): array
    {
        return [
            'run' => $row->run,
            'attempt' => $row->attempt,
            'max_attempts' => $pipeline->maxAttempts(),
            'recipient' => $row->recipient,
            'outcome' => $row->outcome?->value,
            'error' => $row->error,
            'message_id' => $row->message_id,
            'duration_ms' => $row->duration_ms,
            'package' => $row->package_file_name,
            'attempt_id' => $row->getKey(),
        ];
    }
}
