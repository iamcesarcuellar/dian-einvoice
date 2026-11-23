<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Listing;
use App\Modules\Catalog\Http\Requests\TaxRequest;
use App\Modules\Catalog\Http\Resources\TaxResource;
use App\Modules\Catalog\Models\Tax;
use App\Support\Deletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Tax rates of the current company. Defaults are common values: their name, rate and status can be changed, not their type, and they cannot be deleted.
 */
final class TaxController
{
    /**
     * List taxes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Tax::query()->orderBy('dian_code')->orderByDesc('rate')->orderBy('name');

        return TaxResource::collection(Listing::paginate($query, $request, ['name']));
    }

    /**
     * Create a tax.
     *
     * Owners and admins, in the web app; API keys get 403.
     */
    public function store(TaxRequest $request): JsonResponse
    {
        $tax = Tax::create($request->validated());

        return TaxResource::make($tax)->response()->setStatusCode(201);
    }

    /**
     * Show a tax.
     */
    public function show(Tax $tax): TaxResource
    {
        return TaxResource::make($tax);
    }

    /**
     * Update a tax.
     *
     * Owners and admins, in the web app; API keys get 403.
     */
    public function update(TaxRequest $request, Tax $tax): TaxResource
    {
        $tax->update($request->validated());

        return TaxResource::make($tax);
    }

    /**
     * Delete a tax.
     *
     * Only a tax that no item uses; the defaults cannot be deleted. Owners and admins, in the web
     * app; API keys get 403.
     */
    public function destroy(Tax $tax): Response
    {
        abort_if($tax->is_system, 403, __('catalog::errors.tax_default'));

        Deletion::deleteOrConflict($tax, __('catalog::errors.tax_in_use'));

        return response()->noContent();
    }
}
