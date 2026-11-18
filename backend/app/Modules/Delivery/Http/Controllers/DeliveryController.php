<?php

namespace App\Modules\Delivery\Http\Controllers;

use App\Modules\Billing\Http\Resources\DocumentResource;
use App\Modules\Billing\Models\Document;
use App\Modules\Delivery\Enums\DocumentFileKind;
use App\Modules\Delivery\Http\Requests\DeliverRequest;
use App\Modules\Delivery\Models\DocumentFile;
use App\Modules\Delivery\Pipeline\DeliveryActions;
use App\Modules\Delivery\Services\DocumentFiles;
use App\Modules\Identity\Tenancy\Actor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Customer delivery of an accepted invoice, credit note or debit note: its PDF, its AttachedDocument and the zip the email
 * carries, Send again and Regenerate PDF. Every email goes to the local development mailbox in this
 * project; the documents are test documents validated by a local simulator.
 */
final class DeliveryController
{
    public function __construct(private readonly DocumentFiles $files) {}

    /**
     * The PDF, the graphic representation of the document: inline, for the viewer of the browser, or
     * as a download with ?download=1. 409 while it does not exist.
     */
    public function pdf(Request $request, Document $document): Response
    {
        $file = $this->file($document, DocumentFileKind::Pdf, 'The PDF of this document is not available yet.');

        return $this->serve($file, 'application/pdf', $document->full_number.'.pdf', $request->boolean('download') ? 'attachment' : 'inline');
    }

    /**
     * The signed AttachedDocument (annex 1.9, section 6.4) with the signed document and the
     * ApplicationResponse of the local simulator inside. 409 while it does not exist.
     */
    public function attachedDocument(Document $document): Response
    {
        $file = $this->file($document, DocumentFileKind::AttachedDocument, 'The AttachedDocument of this document is not available yet.');

        return $this->serve($file, 'application/xml; charset=UTF-8', $file->file_name, 'attachment');
    }

    /** The zip the email carries: the AttachedDocument and the PDF. 409 while it does not exist. */
    public function package(Document $document): Response
    {
        $file = $this->file($document, DocumentFileKind::Package, 'The zip of this document is not available yet.');

        return $this->serve($file, 'application/zip', $file->file_name, 'attachment');
    }

    /**
     * Send again, once the delivery has ended: to the current recipient, or to the email sent in the
     * body. It starts from the step that failed, if one did. 409 while a delivery runs; 422 when
     * there is no address at all.
     */
    public function deliver(DeliverRequest $request, Document $document, DeliveryActions $actions): DocumentResource
    {
        return DocumentResource::make($actions->sendAgain($document->getKey(), $request->email(), Actor::user($request))->load(Document::DETAILS));
    }

    /** Regenerate PDF, after it failed or when it is missing; the delivery then goes on. Otherwise 409. */
    public function regeneratePdf(Request $request, Document $document, DeliveryActions $actions): DocumentResource
    {
        return DocumentResource::make($actions->regeneratePdf($document->getKey(), Actor::user($request))->load(Document::DETAILS));
    }

    private function file(Document $document, DocumentFileKind $kind, string $missing): DocumentFile
    {
        if ($document->isDraft()) {
            throw new ConflictHttpException('Drafts are not delivered. Issue the document first.');
        }

        return $this->files->find($document, $kind) ?? throw new ConflictHttpException($missing);
    }

    private function serve(DocumentFile $file, string $type, string $name, string $disposition): Response
    {
        return response($this->files->contents($file), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => sprintf('%s; filename="%s"', $disposition, $name),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
