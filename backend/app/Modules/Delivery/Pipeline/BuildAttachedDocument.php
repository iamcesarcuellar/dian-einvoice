<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Delivery\Services\AttachedDocuments;
use App\Modules\Dian\Pipeline\ChainFailure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivery step 1: builds the AttachedDocument (the signed invoice and the ApplicationResponse of
 * the local simulator, in the container of annex 1.9, section 6.4), signs it and stores it; then the
 * PDF is due. Started with the acceptance, or by Send again when the AttachedDocument failed.
 */
final class BuildAttachedDocument extends DeliveryJob
{
    public function step(): DeliveryStep
    {
        return DeliveryStep::AttachedDocument;
    }

    public function process(DeliveryPipeline $pipeline, AttachedDocuments $attachedDocuments): void
    {
        $failure = DB::transaction(function () use ($pipeline, $attachedDocuments): ?Throwable {
            $document = $pipeline->lock($this->documentId);

            if ($document === null || ! $this->expects($document)) {
                return null;
            }

            try {
                $file = $attachedDocuments->build($document);
            } catch (Throwable $exception) {
                report($exception);
                $pipeline->fail($document, DeliveryStep::AttachedDocument, __('delivery::chain.attached_failed', ['error' => $exception->getMessage()]));

                return new ChainFailure("The AttachedDocument of {$document->full_number} could not be built.", previous: $exception);
            }

            $pipeline->advance($document, DeliveryStep::Pdf);
            $pipeline->timeline($document, DocumentEventType::AttachedDocumentBuilt, [
                'run' => $document->delivery_run,
                'file_name' => $file->file_name,
                'sha256' => $file->sha256,
                'size' => $file->size,
            ]);
            $pipeline->emit($document, DeliveryEvents::PDF_DUE);

            return null;
        }, 3);

        if ($failure !== null) {
            $this->deadLetter($failure);
        }
    }
}
