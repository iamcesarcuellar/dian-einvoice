<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Services\InvoiceXmlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 1: pending → generated. Builds the UBL XML from the frozen copies of the invoice and stores
 * it unsigned; then the signing step is due. Started by invoice.issued, Resend, or Retry now after
 * the generation failed.
 */
final class GenerateXml extends ChainStep
{
    public function step(): string
    {
        return 'generate';
    }

    protected function expects(Document $document): bool
    {
        return $document->electronic_status === ElectronicStatus::Pending;
    }

    public function process(ElectronicDocumentPipeline $pipeline, InvoiceXmlGenerator $generator): void
    {
        $failure = DB::transaction(function () use ($pipeline, $generator): ?Throwable {
            $document = $pipeline->lock($this->documentId);

            if ($document === null || $document->electronic_run !== $this->run || ! $this->expects($document)) {
                return null;
            }

            try {
                $generator->generate($document);
            } catch (Throwable $exception) {
                report($exception);
                $pipeline->fail($document, 'generate', 'Generation failed: '.$exception->getMessage());

                return new ChainFailure("The XML of {$document->full_number} could not be generated.", previous: $exception);
            }

            $pipeline->move($document, ElectronicStatus::Generated, ['xml_generated_at' => Carbon::now()]);
            $pipeline->timeline($document, DocumentEventType::XmlGenerated, ['run' => $document->electronic_run]);
            $pipeline->emit($document, ChainEvents::SIGN);

            return null;
        }, 3);

        if ($failure !== null) {
            $this->deadLetter($failure);
        }
    }
}
