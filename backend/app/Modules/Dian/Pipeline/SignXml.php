<?php

namespace App\Modules\Dian\Pipeline;

use App\Modules\Billing\Enums\DocumentEventType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Services\DocumentXmlGenerator;
use App\Modules\Dian\Services\IssueDateRefresh;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Step 2: generated → signed. Signs the XML with the company certificate (XAdES-EPES) and stores
 * it with its SHA-256, size, signing time and certificate serial; then the first transmission
 * attempt is due. The unsigned copy is discarded after the commit. The signature is dated on the
 * issue date (FAD09e, CAD09e, DAD09e): when the day in Colombia changed since the generation, the
 * issue date and time move to the signing moment and the XML is generated again before it is signed.
 */
final class SignXml extends ChainStep
{
    public function step(): string
    {
        return 'sign';
    }

    protected function expects(Document $document): bool
    {
        return $document->electronic_status === ElectronicStatus::Generated;
    }

    public function process(ElectronicDocumentPipeline $pipeline, DocumentXmlGenerator $generator, IssueDateRefresh $issueDate): void
    {
        /** @var array{0: Document|null, 1: Throwable|null} $result */
        $result = DB::transaction(function () use ($pipeline, $generator, $issueDate): array {
            $document = $pipeline->lock($this->documentId);

            if ($document === null || $document->electronic_run !== $this->run || ! $this->expects($document)) {
                return [null, null];
            }

            try {
                // One instant for both: the signature falls on the issue date.
                $now = Carbon::now();
                $moved = $issueDate->refresh($document, $now);

                if ($moved !== null) {
                    $pipeline->timeline($document, DocumentEventType::IssueDateMoved, ['run' => $document->electronic_run, 'step' => 'sign', ...$moved]);
                    $generator->generate($document);
                }

                $signed = $generator->sign($document, $now);
            } catch (Throwable $exception) {
                report($exception);
                $pipeline->fail($document, 'sign', 'Signing failed: '.$exception->getMessage());

                return [null, new ChainFailure("The XML of {$document->full_number} could not be signed.", previous: $exception)];
            }

            $pipeline->move($document, ElectronicStatus::Signed, [
                'xml_path' => $signed->stored->path,
                'xml_sha256' => $signed->stored->sha256,
                'xml_size' => $signed->stored->size,
                'xml_signed_at' => $signed->signedAt,
                'signing_certificate_serial' => $signed->certificate->serial_number,
            ]);
            $pipeline->timeline($document, DocumentEventType::XmlSigned, [
                'run' => $document->electronic_run,
                'certificate_serial' => $signed->certificate->serial_number,
                'test_certificate' => $signed->certificate->is_test,
                'sha256' => $signed->stored->sha256,
                'size' => $signed->stored->size,
            ]);
            $pipeline->emit($document, ChainEvents::TRANSMIT, ['attempt' => 1]);

            return [$document, null];
        }, 3);

        [$document, $failure] = $result;

        if ($failure !== null) {
            $this->deadLetter($failure);
        } elseif ($document !== null) {
            $generator->discardUnsigned($document);
        }
    }
}
