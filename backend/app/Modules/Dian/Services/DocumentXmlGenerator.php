<?php

namespace App\Modules\Dian\Services;

use App\Modules\Billing\Models\Document;
use App\Modules\Dian\Signing\XadesSigner;
use App\Modules\Dian\Ubl\UblDocumentXml;
use App\Support\Storage\DocumentStorage;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The two XML steps of the electronic document chain. generate() builds the UBL XML of an issued
 * invoice, credit note or debit note from its frozen copies and stores it unsigned; sign() signs it with the company
 * certificate (XAdES-EPES) and stores the signed XML, which is the only one kept: the unsigned
 * copy is discarded once the signature is recorded. Both throw on failure; the chain records it.
 */
final class DocumentXmlGenerator
{
    public function __construct(
        private readonly UblDocumentXml $builder,
        private readonly XadesSigner $signer,
        private readonly SigningCertificates $certificates,
        private readonly DocumentStorage $storage,
    ) {}

    public function generate(Document $document): void
    {
        $this->storage->put($this->unsignedPath($document), $this->builder->build($document));
    }

    /**
     * Signs the stored unsigned XML at $signedAt (now by default); if it is missing, such as after a
     * cleanup, it is built again.
     */
    public function sign(Document $document, ?Carbon $signedAt = null): SignedXml
    {
        $path = $this->unsignedPath($document);
        $xml = $this->storage->exists($path) ? $this->storage->get($path) : $this->builder->build($document);

        $certificate = $this->certificates->current()
            ?? throw new RuntimeException('The company has no signing certificate.');
        $signedAt ??= Carbon::now();

        // Valid at the instant of the signature: one that expired after the document was issued
        // stops the chain here, before anything is signed or sent.
        $status = $certificate->status($signedAt);

        if (! $status->usable()) {
            throw new RuntimeException("The signing certificate {$certificate->serial_number} is {$status->value} on {$signedAt->toDateString()}. Upload a valid certificate in the DIAN settings, then retry.");
        }
        $signed = $this->signer->sign($xml, $this->certificates->credentials($certificate), $signedAt);

        return new SignedXml($this->storage->put($this->path($document), $signed), $signedAt, $certificate);
    }

    public function discardUnsigned(Document $document): void
    {
        $this->storage->delete($this->unsignedPath($document));
    }

    /** Path of the signed XML in the document storage: company, document type and full number. */
    public function path(Document $document): string
    {
        return sprintf('%s/%s/%s.xml', $document->company_id, $document->type->value, $document->full_number);
    }

    private function unsignedPath(Document $document): string
    {
        return sprintf('%s/%s/%s.unsigned.xml', $document->company_id, $document->type->value, $document->full_number);
    }
}
