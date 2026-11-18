<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStatus;
use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Services\DocumentFiles;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * What a user can do on the delivery of an accepted document. Send again: a new run that emails
 * the zip to the same recipient or to another one; after a failure it starts from the step that
 * failed. Regenerate PDF: a new run from the PDF after it failed (from the AttachedDocument when
 * that is missing too), which then goes on to the email.
 */
final class DeliveryActions
{
    public function __construct(
        private readonly DeliveryPipeline $pipeline,
        private readonly DocumentFiles $files,
    ) {}

    public function sendAgain(string $documentId, ?string $email, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($documentId, $email, $actor): Document {
            $document = $this->deliverable($documentId);
            $previous = $document->delivery_recipient;
            $recipient = $email ?? $previous ?? DeliveryPipeline::defaultRecipient($document);

            if ($recipient === null) {
                throw ValidationException::withMessages(['email' => ['The customer has no email address. Enter the address to send the invoice to.']]);
            }

            $step = $document->delivery_status === DeliveryStatus::Failed && $document->delivery_step !== null
                ? $document->delivery_step
                : $this->firstMissing($document);

            $this->pipeline->begin($document, $step, $document->delivery_run + 1, $recipient);
            $this->pipeline->timeline($document, DocumentEventType::DeliveryRequested, [
                'run' => $document->delivery_run,
                'recipient' => $recipient,
                'previous_recipient' => $previous,
                'step' => $step->value,
            ], $actor);

            return $document;
        }, 3);
    }

    public function regeneratePdf(string $documentId, ?User $actor = null): Document
    {
        return DB::transaction(function () use ($documentId, $actor): Document {
            $document = $this->deliverable($documentId);
            $pdfMissing = $this->files->find($document, DocumentFileKind::Pdf) === null;
            $failedBeforeEmail = $document->delivery_status === DeliveryStatus::Failed
                && in_array($document->delivery_step, [DeliveryStep::AttachedDocument, DeliveryStep::Pdf], true);

            if (! $failedBeforeEmail && ! $pdfMissing) {
                throw new ConflictHttpException('Regenerate PDF is only available after the PDF failed or when it is missing.');
            }

            $step = $this->files->find($document, DocumentFileKind::AttachedDocument) === null
                ? DeliveryStep::AttachedDocument
                : DeliveryStep::Pdf;

            $this->pipeline->begin($document, $step, $document->delivery_run + 1, $document->delivery_recipient ?? DeliveryPipeline::defaultRecipient($document));
            $this->pipeline->timeline($document, DocumentEventType::PdfRegenerationRequested, [
                'run' => $document->delivery_run,
                'step' => $step->value,
            ], $actor);

            return $document;
        }, 3);
    }

    /** The document locked, accepted and with its delivery ended; otherwise 404 or 409. */
    private function deliverable(string $documentId): Document
    {
        $document = $this->pipeline->lock($documentId) ?? throw new NotFoundHttpException;

        if ($document->electronic_status !== ElectronicStatus::Accepted || $document->delivery_status === null) {
            throw new ConflictHttpException('Only invoices accepted by the local simulator are delivered to the customer.');
        }

        if ($document->delivery_status === DeliveryStatus::Pending) {
            throw new ConflictHttpException('The delivery of this invoice is running right now.');
        }

        return $document;
    }

    /** The first step whose file is missing; the email when both files exist. */
    private function firstMissing(Document $document): DeliveryStep
    {
        return match (true) {
            $this->files->find($document, DocumentFileKind::AttachedDocument) === null => DeliveryStep::AttachedDocument,
            $this->files->find($document, DocumentFileKind::Pdf) === null => DeliveryStep::Pdf,
            default => DeliveryStep::Email,
        };
    }
}
