<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\InvoiceBalance;
use App\Modules\Delivery\Pipeline\DeliveryPipeline;
use App\Modules\Dian\Models\DianTransmission;
use App\Support\Observability\LogEvent;
use App\Support\Observability\Telemetry;
use Illuminate\Support\Facades\DB;

/**
 * Step 4: sending → accepted or rejected, from the answer a transmission call received (SendBillSync,
 * or GetStatus that found the document already processed). The document keeps the status code,
 * description, message, the rules with their messages and the ApplicationResponse, all from the
 * local simulator. A rejection is final: rules are not retried; Resend sends it again. An
 * acceptance starts the customer delivery in the same transaction (AttachedDocument, PDF, email);
 * the acceptance of a note also updates the balance status of its invoice, locked after the note.
 */
final class RecordDianResponse extends ChainStep
{
    public function step(): string
    {
        return 'transmit';
    }

    protected function expects(Document $document): bool
    {
        return $document->electronic_status === ElectronicStatus::Sending;
    }

    public function process(ElectronicDocumentPipeline $pipeline, DeliveryPipeline $delivery, InvoiceBalance $balances): void
    {
        $recorded = DB::transaction(function () use ($pipeline, $delivery, $balances): ?array {
            $document = $pipeline->lock($this->documentId);

            if ($document === null || $document->electronic_run !== $this->run || ! $this->expects($document) || $this->transmissionId === null) {
                return null;
            }

            $transmission = DianTransmission::query()
                ->whereKey($this->transmissionId)
                ->where('document_id', $document->getKey())
                ->first();

            if ($transmission === null || $transmission->run !== $document->electronic_run || $transmission->is_valid === null) {
                return null;
            }

            $accepted = $transmission->is_valid;
            $messages = $transmission->messages ?? [];
            $rejections = array_values(array_filter($messages, fn (array $message): bool => $message['kind'] === 'rejection'));
            $notifications = array_values(array_filter($messages, fn (array $message): bool => $message['kind'] === 'notification'));

            $pipeline->move($document, $accepted ? ElectronicStatus::Accepted : ElectronicStatus::Rejected, [
                'electronic_next_attempt_at' => null,
                'dian_gateway' => $transmission->gateway,
                'dian_status_code' => $transmission->status_code,
                'dian_status_description' => $transmission->status_description,
                'dian_status_message' => $transmission->status_message,
                'dian_messages' => $messages,
                'dian_response_path' => $transmission->response_path,
                'dian_responded_at' => $transmission->finished_at,
            ]);

            $summary = [
                'run' => $document->electronic_run,
                'attempt' => $transmission->attempt,
                'operation' => $transmission->operation,
                'gateway' => $transmission->gateway,
                'status_code' => $transmission->status_code,
                'rules' => array_values(array_filter(array_column($accepted ? $notifications : $rejections, 'rule'))),
                'notifications' => count($notifications),
            ];

            $pipeline->timeline($document, $accepted ? DocumentEventType::Accepted : DocumentEventType::Rejected, $summary);
            $pipeline->emit($document, $accepted ? ChainEvents::ACCEPTED : ChainEvents::REJECTED, [
                'full_number' => $document->full_number,
                'cufe' => $document->cufe,
                ...$summary,
            ]);

            if ($accepted && $document->isNote() && $document->related_document_id !== null) {
                $invoice = Document::query()->whereKey($document->related_document_id)->lockForUpdate()->first();

                if ($invoice !== null) {
                    $balances->noteAccepted($invoice, $document);
                }
            }

            if ($accepted) {
                $delivery->start($document);
            }

            return ['accepted' => $accepted, 'type' => $document->type->value, 'status_code' => $transmission->status_code, 'messages' => $messages];
        }, 3);

        if ($recorded !== null) {
            $this->log($recorded);
        }
    }

    /**
     * After the commit: the answer of the DIAN, and one line per rule that rejected the document or notified
     * something about it, by its id (never the text of the DIAN, which may quote the document).
     *
     * @param  array{accepted: bool, type: string, status_code: string|null, messages: list<array{rule: string|null, kind: string, message: string}>}  $recorded
     */
    private function log(array $recorded): void
    {
        Telemetry::record(
            LogEvent::DianResponse,
            $recorded['accepted'] ? 'The DIAN accepted the document.' : 'The DIAN rejected the document.',
            outcome: $recorded['accepted'] ? 'accepted' : 'rejected',
            documentId: $this->documentId,
            detail: ['status_code' => $recorded['status_code'], 'document_type' => $recorded['type'], 'count' => count($recorded['messages'])],
            level: $recorded['accepted'] ? 'info' : 'warning',
        );

        foreach ($recorded['messages'] as $message) {
            if ($message['rule'] === null) {
                continue;
            }

            $rejection = $message['kind'] === 'rejection';
            Telemetry::record(
                LogEvent::DianRule,
                sprintf('Rule %s of the DIAN %s the document.', $message['rule'], $rejection ? 'rejected' : 'notified about'),
                outcome: $rejection ? 'rejected' : 'accepted',
                documentId: $this->documentId,
                detail: ['rule' => $message['rule'], 'document_type' => $recorded['type']],
                level: $rejection ? 'warning' : 'info',
            );
        }
    }
}
