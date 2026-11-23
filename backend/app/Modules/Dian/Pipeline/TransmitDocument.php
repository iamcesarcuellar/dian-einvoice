<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Dian\Models\DianTransmission;
use App\Modules\Dian\Simulator\SimulatorUnavailable;
use App\Modules\Dian\Simulator\TaxpayerRegistry;
use App\Modules\Dian\Transmission\DianGateway;
use App\Modules\Dian\Transmission\DianPackage;
use App\Modules\Dian\Transmission\GatewayCall;
use App\Modules\Dian\Transmission\TransmissionOutcome;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Step 3: signed → sending, one transmission attempt to the DIAN web service, which in this
 * project is always the local simulator.
 *
 * 1. Claim, in a transaction: lock the document and check the attempt the event asks for is the
 *    next one, or one whose worker stopped during its call (its row never finished and is older
 *    than the lease). The first attempt of a run takes the zip and XML names. The call is written
 *    to dian_transmissions before it starts, so a crash during it is never invisible.
 * 2. Call, outside any transaction: GetStatus first when an earlier send of this run may have
 *    arrived (it went out and no answer came back), else SendBillSync with the zip.
 * 3. Record, in a transaction: the call result, its timeline entry and the next event. An answer
 *    (SendBillSync, or GetStatus that found the document) makes the response step due. GetStatus
 *    that did not find it sends right away, in the same attempt. A SendBillSync answered with rule
 *    90 (annex 1.9: "Documento procesado anteriormente", a number is transmitted only once) asks
 *    GetStatus in the same attempt: if the DIAN knows this CUFE, its answer is the one kept (the
 *    document had arrived before); if not, the number belongs to another document and the rule 90
 *    rejection stands. A transient failure schedules the
 *    next attempt (2, 5, 15, 45 and 120 s plus jitter, configurable); after the last one the
 *    document fails and the job goes to the dead letter queue. A 4xx fails it at once.
 */
final class TransmitDocument extends ChainStep
{
    /** Rule of annex 1.9 for a number already processed for the issuer. */
    private const string PROCESSED_BEFORE = '90';

    public function step(): string
    {
        return 'transmit';
    }

    protected function expects(Document $document): bool
    {
        return $document->electronic_status === ElectronicStatus::Signed || $document->electronic_status === ElectronicStatus::Sending;
    }

    public function process(
        ElectronicDocumentPipeline $pipeline,
        DianGateway $gateway,
        TaxpayerRegistry $registry,
        DianPackage $package,
        DocumentStorage $storage,
        CurrentCompany $currentCompany,
    ): void {
        $transmission = DB::transaction(fn (): ?DianTransmission => $this->claim($pipeline, $gateway, $package), 3);

        while ($transmission !== null) {
            $call = $this->perform($transmission, $gateway, $registry, $package, $storage, $currentCompany);

            /** @var array{0: DianTransmission|null, 1: ChainFailure|null} $next */
            $next = DB::transaction(fn (): array => $this->record($transmission, $call, $pipeline, $gateway, $storage), 3);
            [$transmission, $failure] = $next;

            if ($failure !== null) {
                $this->deadLetter($failure);

                return;
            }
        }
    }

    private function claim(ElectronicDocumentPipeline $pipeline, DianGateway $gateway, DianPackage $package): ?DianTransmission
    {
        $document = $pipeline->lock($this->documentId);

        if ($document === null || $document->electronic_run !== $this->run) {
            return null;
        }

        if ($document->electronic_status === ElectronicStatus::Signed) {
            if ($this->attempt !== 1 || $document->electronic_attempt !== 0) {
                return null;
            }

            $names = $package->nextNames($document->company_id, $document->companySnapshot()['identification_number'], (int) Carbon::now(NumberTemplate::TIMEZONE)->format('Y'), $document->type->filePrefix());

            $pipeline->move($document, ElectronicStatus::Sending, [
                'dian_zip_file_name' => $names['zip'],
                'dian_xml_file_name' => $names['xml'],
                'electronic_attempt' => 1,
                'electronic_next_attempt_at' => null,
            ]);
        } elseif ($document->electronic_status === ElectronicStatus::Sending) {
            $running = $this->unfinished($document);

            if ($document->electronic_attempt === $this->attempt - 1 && $running === null) {
                $document->fill(['electronic_attempt' => $this->attempt, 'electronic_next_attempt_at' => null])->save();
            } elseif ($document->electronic_attempt === $this->attempt && $running !== null) {
                // The worker of this attempt stopped during its call: the job came back, or the relay
                // found the chain stalled. Take over only once that call cannot be running any more.
                if ($running->started_at->gt(Carbon::now()->subSeconds($pipeline->lease()))) {
                    return null;
                }

                $running->fill([
                    'finished_at' => Carbon::now(),
                    'outcome' => TransmissionOutcome::Abandoned,
                    'may_have_arrived' => $running->operation === DianGateway::SEND_BILL_SYNC,
                    'error' => 'No response was recorded: the worker stopped during the call.',
                ])->save();

                $pipeline->timeline($document, DocumentEventType::TransmissionAttempt, $this->payload($running, $pipeline));
            } else {
                return null;
            }
        } else {
            return null;
        }

        return $this->start($document, $this->lastSendMayHaveArrived($document) ? DianGateway::GET_STATUS : DianGateway::SEND_BILL_SYNC, $gateway);
    }

    private function perform(
        DianTransmission $transmission,
        DianGateway $gateway,
        TaxpayerRegistry $registry,
        DianPackage $package,
        DocumentStorage $storage,
        CurrentCompany $currentCompany,
    ): GatewayCall {
        $document = Document::query()->findOrFail($transmission->document_id);

        if ($transmission->operation === DianGateway::GET_STATUS) {
            return $gateway->getStatus((string) $document->cufe);
        }

        // The simulator must know the company, its software and its ranges; nothing is registered by hand.
        try {
            $registry->ensureRegistered($currentCompany->company());
        } catch (SimulatorUnavailable $exception) {
            return new GatewayCall(DianGateway::SEND_BILL_SYNC, TransmissionOutcome::Refused, 0, error: 'The company could not be registered with the local simulator: '.($exception->getPrevious()?->getMessage() ?? $exception->getMessage()));
        }

        $zip = $package->zip((string) $document->dian_xml_file_name, $storage->get((string) $document->xml_path));

        return $gateway->sendBillSync((string) $document->dian_zip_file_name, $zip);
    }

    /**
     * @return array{0: DianTransmission|null, 1: ChainFailure|null} The next call of this attempt, and the failure that ended the chain.
     */
    private function record(DianTransmission $transmission, GatewayCall $call, ElectronicDocumentPipeline $pipeline, DianGateway $gateway, DocumentStorage $storage): array
    {
        $document = $pipeline->lock($transmission->document_id);
        $row = DianTransmission::query()->whereKey($transmission->getKey())->lockForUpdate()->first();

        // Another worker took this call over as abandoned: its own call decides.
        if ($document === null || $row === null || $row->finished_at !== null) {
            return [null, null];
        }

        $row->fill([
            'finished_at' => Carbon::now(),
            'duration_ms' => $call->durationMs,
            'outcome' => $call->outcome,
            'may_have_arrived' => $call->mayHaveArrived,
            'http_status' => $call->httpStatus,
            'status_code' => $call->answer?->statusCode,
            'status_description' => $call->answer?->statusDescription,
            'status_message' => $call->answer?->statusMessage,
            'is_valid' => $call->outcome === TransmissionOutcome::NotFound ? null : $call->answer?->isValid,
            'messages' => $call->answer?->messages,
            'error' => $call->error,
        ]);

        if ($call->answer?->applicationResponse !== null && $call->outcome !== TransmissionOutcome::NotFound) {
            $row->response_path = $storage->put(
                sprintf('%s/%s/responses/%s-%s.xml', $document->company_id, $document->type->value, $document->full_number, $row->id),
                $call->answer->applicationResponse,
            )->path;
        }

        $row->save();

        // Retry now, or a takeover, moved the document on meanwhile.
        if ($document->electronic_status !== ElectronicStatus::Sending || $document->electronic_run !== $row->run || $document->electronic_attempt !== $row->attempt) {
            return [null, null];
        }

        $payload = $this->payload($row, $pipeline);

        $repeated = $call->outcome === TransmissionOutcome::NotFound ? $this->processedBefore($row) : null;

        return match (true) {
            $call->outcome === TransmissionOutcome::Responded && $this->isProcessedBefore($call) => $this->askStatus($document, $payload, $pipeline, $gateway),
            $call->outcome === TransmissionOutcome::Responded, $call->outcome === TransmissionOutcome::Found => $this->answered($document, $row, $payload, $pipeline),
            $repeated !== null => $this->answered($document, $repeated, $payload, $pipeline),
            $call->outcome === TransmissionOutcome::NotFound => $this->sendNow($document, $payload, $pipeline, $gateway),
            $call->outcome->isTransient() => $this->retryOrFail($document, $row, $payload, $pipeline),
            default => $this->failNow($document, $row, $payload, $pipeline),
        };
    }

    /**
     * SendBillSync answered with rule 90: GetStatus, in this same attempt, decides whether this
     * document had arrived before.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: DianTransmission, 1: null}
     */
    private function askStatus(Document $document, array $payload, ElectronicDocumentPipeline $pipeline, DianGateway $gateway): array
    {
        $pipeline->timeline($document, DocumentEventType::TransmissionAttempt, $payload);

        return [$this->start($document, DianGateway::GET_STATUS, $gateway), null];
    }

    /** A rejection with rule 90 (annex 1.9, "Documento procesado anteriormente"). */
    private function isProcessedBefore(GatewayCall $call): bool
    {
        return $call->operation === DianGateway::SEND_BILL_SYNC
            && $call->answer !== null
            && ! $call->answer->isValid
            && in_array(self::PROCESSED_BEFORE, array_column($call->answer->messages, 'rule'), true);
    }

    /**
     * The SendBillSync of this attempt answered with rule 90, when GetStatus then did not find the
     * document: that rejection is the answer.
     */
    private function processedBefore(DianTransmission $getStatus): ?DianTransmission
    {
        $send = DianTransmission::query()
            ->where('document_id', $getStatus->document_id)
            ->where('run', $getStatus->run)
            ->where('attempt', $getStatus->attempt)
            ->where('operation', DianGateway::SEND_BILL_SYNC)
            ->where('outcome', TransmissionOutcome::Responded)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        $rules = array_column($send->messages ?? [], 'rule');

        return $send !== null && $send->is_valid === false && in_array(self::PROCESSED_BEFORE, $rules, true) ? $send : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: null, 1: null}
     */
    private function answered(Document $document, DianTransmission $row, array $payload, ElectronicDocumentPipeline $pipeline): array
    {
        $pipeline->timeline($document, DocumentEventType::TransmissionAttempt, $payload);
        $pipeline->emit($document, ChainEvents::RESPONSE_RECEIVED, ['transmission_id' => $row->getKey()]);

        return [null, null];
    }

    /**
     * GetStatus did not find the document: it never arrived, so it is sent in this same attempt.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: DianTransmission, 1: null}
     */
    private function sendNow(Document $document, array $payload, ElectronicDocumentPipeline $pipeline, DianGateway $gateway): array
    {
        $pipeline->timeline($document, DocumentEventType::TransmissionAttempt, $payload);

        return [$this->start($document, DianGateway::SEND_BILL_SYNC, $gateway), null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: null, 1: ChainFailure|null}
     */
    private function retryOrFail(Document $document, DianTransmission $row, array $payload, ElectronicDocumentPipeline $pipeline): array
    {
        if ($row->attempt < $pipeline->maxAttempts()) {
            $delay = $pipeline->retryDelay($row->attempt);
            $next = Carbon::now()->addSeconds($delay);

            $document->fill(['electronic_next_attempt_at' => $next])->save();
            $pipeline->timeline($document, DocumentEventType::TransmissionAttempt, [...$payload, 'retry_in' => $delay, 'next_attempt_at' => $next->toIso8601String()]);
            $pipeline->emit($document, ChainEvents::TRANSMIT, ['attempt' => $row->attempt + 1], $next);

            return [null, null];
        }

        return $this->failNow($document, $row, $payload, $pipeline);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: null, 1: ChainFailure}
     */
    private function failNow(Document $document, DianTransmission $row, array $payload, ElectronicDocumentPipeline $pipeline): array
    {
        $reason = sprintf('Attempt %d of %d: %s', $row->attempt, $pipeline->maxAttempts(), $row->error ?? $row->outcome->value ?? 'no response');

        $pipeline->timeline($document, DocumentEventType::TransmissionAttempt, $payload);
        $pipeline->fail($document, 'transmit', $reason, $row->attempt);

        return [null, new ChainFailure("The transmission of {$document->full_number} failed. {$reason}")];
    }

    /** Writes the call to dian_transmissions before it starts. */
    private function start(Document $document, string $operation, DianGateway $gateway): DianTransmission
    {
        return DianTransmission::create([
            'document_id' => $document->getKey(),
            'run' => $document->electronic_run,
            'attempt' => $document->electronic_attempt,
            'operation' => $operation,
            'gateway' => $gateway->name(),
            'endpoint' => $gateway->endpoint(),
            'zip_file_name' => $operation === DianGateway::SEND_BILL_SYNC ? $document->dian_zip_file_name : null,
            'started_at' => Carbon::now(),
        ]);
    }

    /** The call of the current attempt that never finished, if any. */
    private function unfinished(Document $document): ?DianTransmission
    {
        return DianTransmission::query()
            ->where('document_id', $document->getKey())
            ->where('run', $document->electronic_run)
            ->whereNull('finished_at')
            ->latest('started_at')
            ->first();
    }

    /** Whether the last SendBillSync of this run went out without an answer. */
    private function lastSendMayHaveArrived(Document $document): bool
    {
        $last = DianTransmission::query()
            ->where('document_id', $document->getKey())
            ->where('run', $document->electronic_run)
            ->where('operation', DianGateway::SEND_BILL_SYNC)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        return $last?->may_have_arrived === true;
    }

    /**
     * The timeline entry of one call.
     *
     * @return array<string, mixed>
     */
    private function payload(DianTransmission $row, ElectronicDocumentPipeline $pipeline): array
    {
        return [
            'run' => $row->run,
            'attempt' => $row->attempt,
            'max_attempts' => $pipeline->maxAttempts(),
            'operation' => $row->operation,
            'outcome' => $row->outcome?->value,
            'duration_ms' => $row->duration_ms,
            'http_status' => $row->http_status,
            'status_code' => $row->status_code,
            'is_valid' => $row->is_valid,
            'error' => $row->error,
            'gateway' => $row->gateway,
            'transmission_id' => $row->getKey(),
        ];
    }
}
