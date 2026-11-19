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
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Tax::query()->orderBy('dian_code')->orderByDesc('rate')->orderBy('name');

        return TaxResource::collection(Listing::paginate($query, $request, ['name']));
    }

    public function store(TaxRequest $request): JsonResponse
    {
        $tax = Tax::create($request->validated());

        return TaxResource::make($tax)->response()->setStatusCode(201);
    }

    public function show(Tax $tax): TaxResource
    {
        return TaxResource::make($tax);
    }

    public function update(TaxRequest $request, Tax $tax): TaxResource
    {
        $tax->update($request->validated());

        return TaxResource::make($tax);
    }

    /**
     * Delete a tax that no item uses. Defaults cannot be deleted.
     */
    public function destroy(Tax $tax): Response
    {
        abort_if($tax->is_system, 403, 'Default taxes cannot be deleted. Deactivate them instead.');

        Deletion::deleteOrConflict($tax, 'This tax is used by items or documents. Deactivate it instead.');

        return response()->noContent();
    }
}
