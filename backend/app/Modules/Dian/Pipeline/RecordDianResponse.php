<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Models\DianTransmission;
use Illuminate\Support\Facades\DB;

/**
 * Step 4: sending → accepted or rejected, from the answer a transmission call received (SendBillSync,
 * or GetStatus that found the document already processed). The document keeps the status code,
 * description, message, the rules with their messages and the ApplicationResponse, all from the
 * local simulator. A rejection is final: rules are not retried; Resend sends it again.
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

    public function process(ElectronicDocumentPipeline $pipeline): void
    {
        DB::transaction(function () use ($pipeline): void {
            $document = $pipeline->lock($this->documentId);

            if ($document === null || $document->electronic_run !== $this->run || ! $this->expects($document) || $this->transmissionId === null) {
                return;
            }

            $transmission = DianTransmission::query()
                ->whereKey($this->transmissionId)
                ->where('document_id', $document->getKey())
                ->first();

            if ($transmission === null || $transmission->run !== $document->electronic_run || $transmission->is_valid === null) {
                return;
            }

            $accepted = $transmission->is_valid;
            $messages = $transmission->messages ?? [];
            $rejections = array_values(array_filter($messages, fn (array $message): bool => ($message['kind'] ?? null) === 'rejection'));
            $notifications = array_values(array_filter($messages, fn (array $message): bool => ($message['kind'] ?? null) === 'notification'));

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
        }, 3);
    }
}
