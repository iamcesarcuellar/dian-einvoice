<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Listing;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Http\Requests\InvoiceRequest;
use App\Modules\Billing\Http\Resources\CalculationResource;
use App\Modules\Billing\Http\Resources\DocumentResource;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\DocumentPricing;
use App\Modules\Billing\Services\InvoiceDrafts;
use App\Modules\Billing\Services\IssueInvoice;
use App\Modules\Dian\Services\InvoiceXmlGenerator;
use App\Support\Storage\DocumentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Invoices of the current company: drafts that can change, and issued invoices that cannot.
 */
final class InvoiceController
{
    /**
     * Drafts first, then issued invoices from the newest number down. ?status=draft|issued,
     * ?from= and ?to= (dates of issue, or of creation for drafts) and ?search= (number or customer).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = Document::query()
            ->where('type', DocumentType::Invoice)
            ->with('contact')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereRaw('coalesce(issue_date, date(created_at)) >= ?', [$from]))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereRaw('coalesce(issue_date, date(created_at)) <= ?', [$to]))
            ->orderByRaw('issued_at is null desc')
            ->orderByDesc('number')
            ->orderByDesc('created_at');

        return DocumentResource::collection(Listing::paginate($query, $request, [], function (Builder $query, string $pattern): void {
            $query->where('full_number', 'like', $pattern)
                ->orWhereHas('contact', fn (Builder $contact) => $contact->where(fn (Builder $match) => $match
                    ->where('legal_name', 'like', $pattern)
                    ->orWhere('trade_name', 'like', $pattern)
                    ->orWhere('identification_number', 'like', $pattern)));
        }));
    }

    /**
     * Create a draft. It can be incomplete; its totals are calculated and stored.
     */
    public function store(InvoiceRequest $request, InvoiceDrafts $drafts): JsonResponse
    {
        $invoice = $drafts->create($request->validated(), $request->user());

        return DocumentResource::make($this->withDetails($invoice))->response()->setStatusCode(201);
    }

    /**
     * Calculate lines, taxes, withholdings and totals of a draft body without storing anything.
     */
    public function preview(InvoiceRequest $request, DocumentPricing $pricing): CalculationResource
    {
        $input = $request->validated();
        $lines = $pricing->resolveLines($input['lines'] ?? []);
        $withholdings = $pricing->resolveWithholdings($input['withholding_ids'] ?? []);

        return new CalculationResource($pricing->calculate($lines, $withholdings), $withholdings);
    }

    public function show(Document $invoice): DocumentResource
    {
        return DocumentResource::make($this->withDetails($invoice));
    }

    /**
     * Update a draft. Issued invoices answer 409.
     */
    public function update(InvoiceRequest $request, Document $invoice, InvoiceDrafts $drafts): DocumentResource
    {
        return DocumentResource::make($this->withDetails($drafts->update($invoice, $request->validated())));
    }

    /**
     * Delete a draft. Issued invoices answer 409.
     */
    public function destroy(Document $invoice, InvoiceDrafts $drafts): Response
    {
        $drafts->delete($invoice);

        return response()->noContent();
    }

    /**
     * Issue a draft: it gets the next number of its numbering and becomes final. A second request
     * answers 409; a draft that is not ready, or a numbering that cannot be used, answers 422.
     */
    public function issue(Request $request, Document $invoice, IssueInvoice $issue): DocumentResource
    {
        return DocumentResource::make($this->withDetails($issue($invoice->getKey(), $request->user())));
    }

    /**
     * Download the signed UBL 2.1 XML, named after the invoice number. Drafts, and invoices whose
     * XML is pending or failed, answer 409.
     */
    public function xml(Document $invoice, DocumentStorage $storage): Response
    {
        if ($invoice->xml_status?->available() !== true || $invoice->xml_path === null) {
            throw new ConflictHttpException($invoice->isDraft()
                ? 'Drafts have no XML. Issue the invoice first.'
                : 'The XML of this invoice is not available. Regenerate it.');
        }

        return response($storage->get($invoice->xml_path), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s.xml"', $invoice->full_number),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Generate and sign the XML again from the frozen copies of the invoice, such as after a
     * failure. The result, signed or failed, is in the timeline. Drafts answer 409.
     */
    public function regenerateXml(Request $request, Document $invoice, InvoiceXmlGenerator $generator): DocumentResource
    {
        if ($invoice->isDraft()) {
            throw new ConflictHttpException('Drafts have no XML. Issue the invoice first.');
        }

        return DocumentResource::make($this->withDetails($generator->generate($invoice, $request->user())));
    }

    private function withDetails(Document $document): Document
    {
        return $document->load(['lines.item', 'taxes', 'withholdings', 'events.actor', 'contact.municipality.department']);
    }
}
