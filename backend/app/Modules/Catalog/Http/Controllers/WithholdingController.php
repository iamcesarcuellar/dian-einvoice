<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Listing;
use App\Modules\Catalog\Http\Requests\WithholdingRequest;
use App\Modules\Catalog\Http\Resources\WithholdingResource;
use App\Modules\Catalog\Models\Withholding;
use App\Support\Deletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Withholding rates of the current company. Defaults are common values: their name, rate and status can be changed, not their type, and they cannot be deleted.
 */
final class WithholdingController
{
    /**
     * List withholdings.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Withholding::query()->orderBy('dian_code')->orderByDesc('rate')->orderBy('name');

        return WithholdingResource::collection(Listing::paginate($query, $request, ['name']));
    }

    /**
     * Create a withholding.
     *
     * Owners and admins, in the web app; API keys get 403.
     */
    public function store(WithholdingRequest $request): JsonResponse
    {
        $withholding = Withholding::create($request->validated());

        return WithholdingResource::make($withholding)->response()->setStatusCode(201);
    }

    /**
     * Show a withholding.
     */
    public function show(Withholding $withholding): WithholdingResource
    {
        return WithholdingResource::make($withholding);
    }

    /**
     * Update a withholding.
     *
     * Owners and admins, in the web app; API keys get 403.
     */
    public function update(WithholdingRequest $request, Withholding $withholding): WithholdingResource
    {
        $withholding->update($request->validated());

        return WithholdingResource::make($withholding);
    }

    /**
     * Delete a withholding.
     *
     * Only a withholding that no document uses; the defaults cannot be deleted. Owners and admins,
     * in the web app; API keys get 403.
     */
    public function destroy(Withholding $withholding): Response
    {
        abort_if($withholding->is_system, 403, __('catalog::errors.withholding_default'));

        Deletion::deleteOrConflict($withholding, __('catalog::errors.withholding_in_use'));

        return response()->noContent();
    }
}
