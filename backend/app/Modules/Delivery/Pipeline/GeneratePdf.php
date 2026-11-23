<?php

namespace App\Modules\Delivery\Pipeline;

use App\Modules\Billing\Enums\DeliveryStep;
use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Delivery\Pdf\InvoicePdfs;
use App\Modules\Dian\Pipeline\ChainFailure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivery step 2: draws the PDF, the graphic representation of the invoice, from its frozen copies
 * and stores it; then packaging and sending are due. Started after the AttachedDocument, or by
 * Regenerate PDF after it failed.
 */
final class GeneratePdf extends DeliveryJob
{
    public function step(): DeliveryStep
    {
        return DeliveryStep::Pdf;
    }

    public function process(DeliveryPipeline $pipeline, InvoicePdfs $pdfs): void
    {
        $failure = DB::transaction(function () use ($pipeline, $pdfs): ?Throwable {
            $document = $pipeline->lock($this->documentId);

            if ($document === null || ! $this->expects($document)) {
                return null;
            }

            try {
                $file = $pdfs->generate($document);
            } catch (Throwable $exception) {
                report($exception);
                $pipeline->fail($document, DeliveryStep::Pdf, __('delivery::chain.pdf_failed', ['error' => $exception->getMessage()]));

                return new ChainFailure("The PDF of {$document->full_number} could not be generated.", previous: $exception);
            }

            $pipeline->advance($document, DeliveryStep::Email, ['delivery_attempt' => 0]);
            $pipeline->timeline($document, DocumentEventType::PdfGenerated, [
                'run' => $document->delivery_run,
                'file_name' => $file->file_name,
                'pages' => $file->pages,
                'sha256' => $file->sha256,
                'size' => $file->size,
            ]);
            $pipeline->emit($document, DeliveryEvents::EMAIL_DUE, ['attempt' => 1]);

            return null;
        }, 3);

        if ($failure !== null) {
            $this->deadLetter($failure);
        }
    }
}
