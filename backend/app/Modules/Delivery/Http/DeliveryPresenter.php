<?php

namespace App\Modules\Delivery\Http;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Delivery\Pipeline\DeliveryPipeline;

/**
 * The delivery block of the invoice resource: its status apart from the electronic one, the
 * recipient, when it was delivered, why it failed, the email attempts and the files the customer
 * gets. Null for drafts. Every email goes to the local development mailbox in this project.
 */
final class DeliveryPresenter
{
    /**
     * @return array<string, mixed>|null
     */
    public static function present(Document $document): ?array
    {
        if ($document->isDraft()) {
            return null;
        }

        $status = $document->delivery_status;
        $files = $document->relationLoaded('files') ? $document->files->keyBy(fn (DocumentFile $file): string => $file->kind->value) : null;
        $waiting = $status === DeliveryStatus::Pending && $document->delivery_next_attempt_at !== null;
        $hasPdf = $files?->has(DocumentFileKind::Pdf->value) ?? false;
        $finished = $status !== null && $status->isFinal();

        return [
            // pending, delivered, failed or not_applicable; null until the local simulator accepts the invoice.
            'status' => $status,
            'final' => $finished,
            // attached_document, pdf or email: the step running while pending, the one that failed while failed.
            'step' => $document->delivery_step,
            'status_changed_at' => $document->delivery_status_at,
            // The delivery's pass: 1 on acceptance, and one more on each Send again or Regenerate PDF.
            'run' => $document->delivery_run,
            'attempt' => $document->delivery_attempt,
            'max_attempts' => app(DeliveryPipeline::class)->maxAttempts(),
            'retrying' => $waiting,
            'next_attempt_at' => $document->delivery_next_attempt_at,
            'recipient' => $document->delivery_recipient,
            // The email frozen with the customer on issue; null for the final consumer.
            'customer_email' => DeliveryPipeline::defaultRecipient($document),
            'delivered_at' => $document->delivered_at,
            'error' => $status === DeliveryStatus::Failed ? $document->delivery_error : null,
            'can_send' => $finished,
            'can_regenerate_pdf' => $finished && (! $hasPdf || ($status === DeliveryStatus::Failed && in_array($document->delivery_step, [DeliveryStep::AttachedDocument, DeliveryStep::Pdf], true))),
            // Always the local mailbox: nothing leaves the machine.
            'mailbox' => 'local',
            'files' => $files === null ? null : [
                'attached_document' => self::file($files->get(DocumentFileKind::AttachedDocument->value)),
                'pdf' => self::file($files->get(DocumentFileKind::Pdf->value), $document->full_number.'.pdf'),
                'package' => self::file($files->get(DocumentFileKind::Package->value)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function file(?DocumentFile $file, ?string $downloadName = null): ?array
    {
        return $file === null ? null : [
            'file_name' => $downloadName ?? $file->file_name,
            'package_name' => $file->file_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'sha256' => $file->sha256,
            'pages' => $file->pages,
            'updated_at' => $file->updated_at,
        ];
    }
}
