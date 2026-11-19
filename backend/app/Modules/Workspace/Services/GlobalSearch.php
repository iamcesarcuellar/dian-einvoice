<?php

namespace App\Modules\Workspace\Services;

use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Models\Document;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Item;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/**
 * The global search of the command palette, over the current company (every model here is scoped
 * to it). Each group answers the total of matches and its first few, newest documents and payments
 * first, contacts by name and items by code. The text is matched with LIKE, as the lists do.
 */
final class GlobalSearch
{
    /** Results shown per group. */
    public const int LIMIT = 5;

    /**
     * @return array{query: string, groups: array<string, array{total: int, items: list<array<string, mixed>>}>}
     */
    public function find(string $term): array
    {
        // The same escaping as the lists (App\Http\Listing): % and _ are literal.
        $pattern = '%'.addcslashes($term, '%_\\').'%';

        return [
            'query' => $term,
            'groups' => [
                'invoices' => $this->documents($pattern, false),
                'notes' => $this->documents($pattern, true),
                'payments' => $this->payments($pattern),
                'contacts' => $this->contacts($pattern),
                'items' => $this->items($pattern),
            ],
        ];
    }

    /**
     * Invoices, or credit and debit notes, by number or customer; a note also by the number of the
     * invoice it corrects. Issued ones newest first, then drafts.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function documents(string $pattern, bool $notes): array
    {
        $types = $notes ? [DocumentType::CreditNote->value, DocumentType::DebitNote->value] : [DocumentType::Invoice->value];

        $query = Document::query()
            ->whereIn('type', $types)
            ->where(function (Builder $query) use ($pattern, $notes): void {
                $query->where('full_number', 'like', $pattern)
                    ->orWhereHas('contact', fn (Builder $contact) => $contact->where(fn (Builder $match) => $match
                        ->where('legal_name', 'like', $pattern)
                        ->orWhere('trade_name', 'like', $pattern)
                        ->orWhere('identification_number', 'like', $pattern)));

                if ($notes) {
                    $query->orWhereHas('relatedDocument', fn (Builder $invoice) => $invoice->where('full_number', 'like', $pattern));
                }
            });

        $total = (clone $query)->count();

        $documents = $query
            ->with($notes ? ['contact:id,legal_name', 'relatedDocument:id,full_number'] : ['contact:id,legal_name'])
            ->orderByRaw('issued_at is null')
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get(['id', 'type', 'status', 'full_number', 'contact_id', 'customer_snapshot', 'related_document_id', 'related_snapshot', 'issue_date', 'issued_at', 'created_at', 'payable_amount', 'electronic_status']);

        return [
            'total' => $total,
            'items' => $documents->map(fn (Document $document): array => [
                'id' => $document->id,
                'type' => $document->type->value,
                'status' => $document->status->value,
                'full_number' => $document->full_number,
                // The customer as issued, or as it is now while the document is a draft.
                'customer_name' => $document->customer_snapshot['legal_name'] ?? $document->contact?->legal_name,
                'issue_date' => $document->issue_date?->toDateString(),
                'payable_amount' => (string) $document->payable_amount,
                'electronic_status' => $document->electronic_status?->value,
                'related_number' => $notes
                    ? ($document->relatedDocument?->full_number ?? $document->related_snapshot['full_number'] ?? null)
                    : null,
            ])->values()->all(),
        ];
    }

    /**
     * Payments by receipt number, customer, customer identification or bank reference, newest first.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function payments(string $pattern): array
    {
        $query = Payment::query()->where(fn (Builder $query) => $query
            ->where('full_number', 'like', $pattern)
            ->orWhere('customer_name', 'like', $pattern)
            ->orWhere('customer_identification', 'like', $pattern)
            ->orWhere('reference', 'like', $pattern));

        $total = (clone $query)->count();

        $payments = $query
            ->orderByDesc('payment_date')
            ->orderByDesc('number')
            ->limit(self::LIMIT)
            ->get(['id', 'full_number', 'number', 'customer_name', 'payment_date', 'amount', 'status']);

        return [
            'total' => $total,
            'items' => $payments->map(fn (Payment $payment): array => [
                'id' => $payment->id,
                'full_number' => $payment->full_number,
                'customer_name' => $payment->customer_name,
                'payment_date' => $payment->payment_date->toDateString(),
                'amount' => (string) $payment->amount,
                'status' => $payment->status->value,
            ])->values()->all(),
        ];
    }

    /**
     * Contacts by legal or trade name, identification number or email, by name.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function contacts(string $pattern): array
    {
        $query = Contact::query()->where(fn (Builder $query) => $query
            ->where('legal_name', 'like', $pattern)
            ->orWhere('trade_name', 'like', $pattern)
            ->orWhere('identification_number', 'like', $pattern)
            ->orWhere('email', 'like', $pattern));

        $total = (clone $query)->count();

        $contacts = $query
            ->orderBy('legal_name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'legal_name', 'trade_name', 'identification_type', 'identification_number', 'check_digit', 'email', 'is_active']);

        return [
            'total' => $total,
            'items' => $contacts->map(fn (Contact $contact): array => [
                'id' => $contact->id,
                'legal_name' => $contact->legal_name,
                'trade_name' => $contact->trade_name,
                'identification_type' => $contact->identification_type->value,
                'identification_number' => $contact->identification_number,
                'check_digit' => $contact->check_digit,
                'email' => $contact->email,
                'is_active' => $contact->is_active,
            ])->values()->all(),
        ];
    }

    /**
     * Items by code or name, by code.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    private function items(string $pattern): array
    {
        $query = Item::query()->where(fn (Builder $query) => $query
            ->where('code', 'like', $pattern)
            ->orWhere('name', 'like', $pattern));

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('code')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'code', 'name', 'type', 'unit_price', 'is_active']);

        return [
            'total' => $total,
            'items' => $items->map(fn (Item $item): array => [
                'id' => $item->id,
                'code' => $item->code,
                'name' => $item->name,
                'type' => $item->type->value,
                // Decimal string with 6 decimals, as the items list answers it.
                'unit_price' => (string) $item->unit_price,
                'is_active' => $item->is_active,
            ])->values()->all(),
        ];
    }
}
