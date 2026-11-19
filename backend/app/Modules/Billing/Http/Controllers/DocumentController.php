<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Listing;
use App\Modules\Billing\Enums\BalanceStatus;
use App\Modules\Billing\Enums\DocumentStatus;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Enums\ElectronicStatus;
use App\Modules\Billing\Enums\InvoicePaymentStatus;
use App\Modules\Billing\Enums\XmlStatus;
use App\Modules\Billing\Http\Requests\DocumentRequest;
use App\Modules\Billing\Http\Resources\CalculationResource;
use App\Modules\Billing\Http\Resources\DocumentResource;
use App\Modules\Billing\Models\Document;
use App\Modules\Billing\Services\DocumentDrafts;
use App\Modules\Billing\Services\DocumentPricing;
use App\Modules\Billing\Services\InvoiceReceivables;
use App\Modules\Billing\Services\IssueDocument;
use App\Modules\Billing\Services\NotePrefill;
use App\Modules\Dian\Pipeline\ElectronicDocumentActions;
use App\Modules\Identity\Tenancy\Actor;
use App\Support\Storage\DocumentStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Invoices, credit notes and debit notes of the current company, under /invoices, /credit-notes
 * and /debit-notes: the route names the type. Drafts can change; issued documents cannot. An
 * issued document goes through the electronic document chain on its own; its DIAN is always the
 * local simulator. A note corrects an accepted invoice and keeps its customer and currency.
 */
final class DocumentController
{
    /** Filter value for every state of a chain still running. */
    private const string PROCESSING = 'processing';

    /**
     * Drafts first, then issued documents from the newest number down. ?status=draft|issued,
     * ?electronic_status=pending|generated|signed|sending|accepted|rejected|failed, or processing
     * for the first four, ?from= and ?to= (dates of issue, or of creation for drafts), ?search=
     * (number or customer; for notes also the number of the invoice), ?balance_status= for
     * invoices and ?related_document_id= for the notes of one invoice, ?contact_id= for one
     * customer, and for invoices ?payment_status=unpaid|partially_paid|paid and ?overdue=1 (accepted
     * invoices with something left to collect after their due date, or their issue date for cash).
     * Rows are summaries: the frozen copies of the company, customer and numbering, and the nested
     * contact, come only with the detail.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $type = DocumentType::forRoute($request->route()?->getName());

        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::enum(DocumentStatus::class)],
            'electronic_status' => ['sometimes', 'nullable', Rule::in([self::PROCESSING, ...array_column(ElectronicStatus::cases(), 'value')])],
            'balance_status' => ['sometimes', 'nullable', Rule::enum(BalanceStatus::class)],
            'related_document_id' => ['sometimes', 'nullable', 'string'],
            'contact_id' => ['sometimes', 'nullable', 'string'],
            'payment_status' => ['sometimes', 'nullable', Rule::enum(InvoicePaymentStatus::class)],
            'overdue' => ['sometimes', 'nullable', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = Document::query()
            ->where('type', $type)
            // The contact only names a draft, which has no frozen copy of its customer yet.
            ->with($type->isNote() ? ['contact:id,legal_name', 'relatedDocument'] : ['contact:id,legal_name'])
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['electronic_status'] ?? null, fn (Builder $query, string $state) => $state === self::PROCESSING
                ? $query->whereIn('electronic_status', ElectronicStatus::processing())
                : $query->where('electronic_status', $state))
            ->when($filters['balance_status'] ?? null, fn (Builder $query, string $state) => $query->where('balance_status', $state))
            ->when($filters['related_document_id'] ?? null, fn (Builder $query, string $id) => $query->where('related_document_id', $id))
            ->when($filters['contact_id'] ?? null, fn (Builder $query, string $id) => $query->where('contact_id', $id))
            ->when($filters['payment_status'] ?? null, fn (Builder $query, string $status) => $query->where('status', DocumentStatus::Issued)->where('payment_status', $status))
            ->when($request->boolean('overdue'), fn (Builder $query) => $query
                ->where('status', DocumentStatus::Issued)
                ->where('electronic_status', ElectronicStatus::Accepted)
                ->where('receivable_amount', '>', 0)
                ->whereRaw('coalesce(due_date, issue_date) < ?', [InvoiceReceivables::today()]))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereRaw('coalesce(issue_date, date(created_at)) >= ?', [$from]))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereRaw('coalesce(issue_date, date(created_at)) <= ?', [$to]))
            ->orderByRaw('issued_at is null desc')
            ->orderByDesc('number')
            ->orderByDesc('created_at');

        return DocumentResource::summaries(Listing::paginate($query, $request, [], function (Builder $query, string $pattern) use ($type): void {
            $query->where('full_number', 'like', $pattern)
                ->orWhereHas('contact', fn (Builder $contact) => $contact->where(fn (Builder $match) => $match
                    ->where('legal_name', 'like', $pattern)
                    ->orWhere('trade_name', 'like', $pattern)
                    ->orWhere('identification_number', 'like', $pattern)));

            if ($type->isNote()) {
                $query->orWhereHas('relatedDocument', fn (Builder $invoice) => $invoice->where('full_number', 'like', $pattern));
            }
        }));
    }

    /**
     * Create a draft. It can be incomplete; its totals are calculated and stored. A note needs the
     * accepted invoice it corrects (related_document_id).
     */
    public function store(DocumentRequest $request, DocumentDrafts $drafts): JsonResponse
    {
        $document = $drafts->create($request->documentType(), $request->validated(), Actor::user($request));

        return DocumentResource::make($this->withDetails($document))->response()->setStatusCode(201);
    }

    /**
     * Calculate lines, taxes, withholdings and totals of a draft body without storing anything.
     */
    public function preview(DocumentRequest $request, DocumentPricing $pricing): CalculationResource
    {
        $input = $request->validated();
        // A note is priced with the rates frozen on its invoice.
        $invoice = $request->documentType()->isNote() && filled($input['related_document_id'] ?? null)
            ? Document::query()->where('type', DocumentType::Invoice)->find($input['related_document_id'])
            : null;
        $lines = $pricing->resolveLines($input['lines'] ?? [], $invoice);
        $withholdings = $pricing->resolveWithholdings($input['withholding_ids'] ?? [], $invoice);

        return new CalculationResource($pricing->calculate($lines, $withholdings), $withholdings);
    }

    /**
     * A credit or debit note not saved yet, for an accepted invoice (?invoice_id=): its customer,
     * payment terms, the lines and withholdings of the invoice and its balance, with what is left
     * to credit. ?void=1 prepares a credit note that voids the invoice: the void concept and all
     * that is left to credit.
     */
    public function prefill(Request $request, NotePrefill $prefill): JsonResponse
    {
        $type = DocumentType::forRoute($request->route()?->getName());
        $input = $request->validate([
            'invoice_id' => ['required', 'string'],
            'void' => ['sometimes', 'boolean'],
        ]);

        $invoice = Document::query()->where('type', DocumentType::Invoice)->findOrFail($input['invoice_id']);

        return response()->json(['data' => $prefill->for($type, $invoice, $request->boolean('void'))]);
    }

    /**
     * A document with its lines, totals, timeline and electronic document: the state of its chain,
     * the current attempt, the time of the next one and the answer of the local simulator; its
     * delivery to the customer; and, for an invoice, its balance and notes, or, for a note, its invoice.
     */
    public function show(Document $document): DocumentResource
    {
        return DocumentResource::make($this->withDetails($document));
    }

    /**
     * Update a draft. Issued documents answer 409.
     */
    public function update(DocumentRequest $request, Document $document, DocumentDrafts $drafts): DocumentResource
    {
        return DocumentResource::make($this->withDetails($drafts->update($document, $request->validated())));
    }

    /**
     * Delete a draft. Issued documents answer 409.
     */
    public function destroy(Document $document, DocumentDrafts $drafts): Response
    {
        $drafts->delete($document);

        return response()->noContent();
    }

    /**
     * Issue a draft: it gets the next number of its numbering and becomes final, and its electronic
     * document chain starts in the background (status pending). A second request answers 409; a
     * draft that is not ready, a numbering that cannot be used, or a credit note above what is left
     * to credit on its invoice, answers 422.
     */
    public function issue(Request $request, Document $document, IssueDocument $issue): DocumentResource
    {
        return DocumentResource::make($this->withDetails($issue($document->getKey(), Actor::user($request))));
    }

    /**
     * Download the signed UBL 2.1 XML, named after the document number. Drafts, and documents whose
     * XML is not signed yet or failed, answer 409.
     */
    public function xml(Document $document, DocumentStorage $storage): Response
    {
        if (XmlStatus::of($document)?->available() !== true || $document->xml_path === null) {
            throw new ConflictHttpException($document->isDraft()
                ? 'Drafts have no XML. Issue the document first.'
                : 'The signed XML of this document is not available yet.');
        }

        return response($storage->get($document->xml_path), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s.xml"', $document->full_number),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Download the ApplicationResponse the local DIAN simulator returned with its last answer. It is
     * a simulated response: the DIAN never saw the document. 409 while there is none.
     */
    public function dianResponse(Document $document, DocumentStorage $storage): Response
    {
        if ($document->dian_response_path === null) {
            throw new ConflictHttpException('The local DIAN simulator has not answered for this document yet.');
        }

        return response($storage->get($document->dian_response_path), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s-simulator-response.xml"', $document->full_number),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Retry now: while a transmission waits for its next attempt, make it now; after a failure,
     * start again from the step that failed. Otherwise 409.
     */
    public function retry(Request $request, Document $document, ElectronicDocumentActions $actions): DocumentResource
    {
        return DocumentResource::make($this->withDetails($actions->retryNow($document->getKey(), Actor::user($request))));
    }

    /**
     * Resend after the local simulator rejected the document: the chain runs again from the
     * generation of the XML. A credit note must still fit what is left to credit. Otherwise 409.
     */
    public function resend(Request $request, Document $document, ElectronicDocumentActions $actions): DocumentResource
    {
        return DocumentResource::make($this->withDetails($actions->resend($document->getKey(), Actor::user($request))));
    }

    private function withDetails(Document $document): Document
    {
        return $document->load(Document::DETAILS);
    }
}
