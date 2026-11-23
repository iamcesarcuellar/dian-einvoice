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
     * Get the PDF.
     *
     * The document's graphic representation: inline, for the browser's viewer, or as a download
     * with ?download=1. 409 while it does not exist.
     */
    public function pdf(Request $request, Document $document): Response
    {
        $file = $this->file($document, DocumentFileKind::Pdf, __('delivery::errors.missing.pdf'));

        return $this->serve($file, 'application/pdf', $document->full_number.'.pdf', $request->boolean('download') ? 'attachment' : 'inline');
    }

    /**
     * Download the AttachedDocument.
     *
     * The signed AttachedDocument (annex 1.9, section 6.4), with the signed document and the local
     * simulator's ApplicationResponse inside. 409 while it does not exist.
     */
    public function attachedDocument(Document $document): Response
    {
        $file = $this->file($document, DocumentFileKind::AttachedDocument, __('delivery::errors.missing.attached_document'));

        return $this->serve($file, 'application/xml; charset=UTF-8', $file->file_name, 'attachment');
    }

    /**
     * Download the email's zip.
     *
     * The zip the email carries: the AttachedDocument and the PDF. 409 while it does not exist.
     */
    public function package(Document $document): Response
    {
        $file = $this->file($document, DocumentFileKind::Package, __('delivery::errors.missing.package'));

        return $this->serve($file, 'application/zip', $file->file_name, 'attachment');
    }

    /**
     * Send again.
     *
     * Once the delivery has ended, the document goes again to the current recipient, or to the email
     * in the body. It starts from the step that failed, if one did. 409 while a delivery runs; 422
     * when there is no address at all.
     */
    public function deliver(DeliverRequest $request, Document $document, DeliveryActions $actions): DocumentResource
    {
        return DocumentResource::make($actions->sendAgain($document->id, $request->email(), Actor::user($request))->load(Document::DETAILS));
    }

    /**
     * Regenerate the PDF.
     *
     * After the PDF failed, or when it is missing; the delivery then goes on. Otherwise 409.
     */
    public function regeneratePdf(Request $request, Document $document, DeliveryActions $actions): DocumentResource
    {
        return DocumentResource::make($actions->regeneratePdf($document->id, Actor::user($request))->load(Document::DETAILS));
    }

    private function file(Document $document, DocumentFileKind $kind, string $missing): DocumentFile
    {
        if ($document->isDraft()) {
            throw new ConflictHttpException(__('delivery::errors.drafts'));
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
