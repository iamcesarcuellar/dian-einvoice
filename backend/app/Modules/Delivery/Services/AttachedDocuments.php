<?php

namespace App\Modules\Delivery\Services;

use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Dian\Services\SigningCertificates;
use App\Modules\Dian\Signing\XadesSigner;
use App\Modules\Dian\Transmission\DianPackage;
use App\Modules\Dian\Ubl\ApplicationResponseSummary;
use App\Modules\Dian\Ubl\AttachedDocumentInput;
use App\Modules\Dian\Ubl\AttachedDocumentXml;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The first delivery step: the AttachedDocument of an accepted invoice, with the signed invoice and
 * the ApplicationResponse of the local simulator inside, signed (XAdES-EPES) with the company
 * certificate and stored as ad{NIT}{ppp}{aa}{dddddddd}.xml (annex 1.9, 6.5.7). The name takes the
 * next "ad" consecutive of the company the first time and keeps it afterwards.
 */
final class AttachedDocuments
{
    /** Who answered for the document, as DianGateway::name() records it. */
    private const string SIMULATOR_GATEWAY = 'simulator';

    public function __construct(
        private readonly AttachedDocumentXml $builder,
        private readonly XadesSigner $signer,
        private readonly SigningCertificates $certificates,
        private readonly DocumentStorage $storage,
        private readonly DianPackage $package,
        private readonly DocumentFiles $files,
    ) {}

    public function build(Document $document): DocumentFile
    {
        if ($document->xml_path === null || $document->dian_response_path === null) {
            throw new RuntimeException('The signed XML or the ApplicationResponse of the local simulator is missing.');
        }

        $invoiceXml = $this->storage->get($document->xml_path);
        $responseXml = $this->storage->get($document->dian_response_path);
        $response = ApplicationResponseSummary::fromXml($responseXml);

        if ($response->documentKey !== null && $response->documentKey !== $document->cufe) {
            throw new RuntimeException('The ApplicationResponse refers to another CUFE.');
        }

        // AE05: the container is never dated before the documents inside it.
        $issuedAt = Carbon::now();
        $validatedAt = $response->issuedAt();

        if ($validatedAt->gt($issuedAt)) {
            $issuedAt = $validatedAt;
        }

        $base = $this->base($document);

        $xml = $this->builder->build($document, new AttachedDocumentInput(
            id: (string) DianPackage::consecutiveOf($base),
            issuedAt: $issuedAt,
            invoiceXml: $invoiceXml,
            responseXml: $responseXml,
            response: $response,
            validator: $document->dian_gateway === self::SIMULATOR_GATEWAY || $document->dian_gateway === null
                ? AttachedDocumentXml::SIMULATOR_VALIDATOR
                : AttachedDocumentXml::DIAN_VALIDATOR,
        ));

        $certificate = $this->certificates->current()
            ?? throw new RuntimeException('The company has no signing certificate.');

        if (! $certificate->status()->usable()) {
            throw new RuntimeException("The signing certificate {$certificate->serial_number} is {$certificate->status()->value}.");
        }

        $signed = $this->signer->sign($xml, $this->certificates->credentials($certificate), $issuedAt);

        return $this->files->store($document, DocumentFileKind::AttachedDocument, self::fileName($base), 'application/xml', $signed);
    }

    public static function fileName(string $base): string
    {
        return DianPackage::ATTACHED_DOCUMENT.$base.'.xml';
    }

    /** The common part of the names, taken once per document from the "ad" counter of the company. */
    private function base(Document $document): string
    {
        $base = $document->delivery_file_base;

        if ($base === null) {
            $base = $document->delivery_file_base = $this->package->nextBase(
                $document->company_id,
                $document->companySnapshot()['identification_number'],
                (int) Carbon::now(NumberTemplate::TIMEZONE)->format('Y'),
                DianPackage::ATTACHED_DOCUMENT,
            );
        }

        return $base;
    }
}
