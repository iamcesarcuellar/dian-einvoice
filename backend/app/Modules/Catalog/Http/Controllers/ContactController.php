<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Listing;
use App\Modules\Catalog\Http\Requests\ContactRequest;
use App\Modules\Catalog\Http\Resources\ContactResource;
use App\Modules\Catalog\Models\Contact;
use App\Support\Deletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Customers and suppliers of the current company.
 */
final class ContactController
{
    /**
     * Contacts, the final consumer first; ?search= matches names, identification and email, and
     * ?active=1 leaves out inactive contacts.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Contact::query()
            ->with('municipality.department')
            ->when($request->boolean('active'), fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_system')
            ->orderBy('legal_name');

        return ContactResource::collection(
            Listing::paginate($query, $request, ['legal_name', 'trade_name', 'identification_number', 'email']),
        );
    }

    /**
     * Create a contact. For a NIT, the check digit is computed by the API.
     */
    public function store(ContactRequest $request): JsonResponse
    {
        $contact = Contact::create($request->validated());

        return ContactResource::make($contact->load('municipality.department'))->response()->setStatusCode(201);
    }

    public function show(Contact $contact): ContactResource
    {
        return ContactResource::make($contact->load('municipality.department'));
    }

    /**
     * Update a contact. The final consumer cannot be edited.
     */
    public function update(ContactRequest $request, Contact $contact): ContactResource
    {
        $contact->update($request->validated());

        return ContactResource::make($contact->load('municipality.department'));
    }

    /**
     * Delete a contact that no document uses; otherwise deactivate it instead.
     */
    public function destroy(Contact $contact): Response
    {
        abort_if($contact->is_system, 403, __('catalog::errors.final_consumer_delete'));

        Deletion::deleteOrConflict($contact, __('catalog::errors.contact_in_use'));

        return response()->noContent();
    }
}
