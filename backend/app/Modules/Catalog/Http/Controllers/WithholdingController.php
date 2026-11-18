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
 * Withholding rates of the current company. Defaults can be deactivated but not edited or deleted.
 */
final class WithholdingController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Withholding::query()->orderBy('dian_code')->orderByDesc('rate')->orderBy('name');

        return WithholdingResource::collection(Listing::paginate($query, $request, ['name']));
    }

    public function store(WithholdingRequest $request): JsonResponse
    {
        $withholding = Withholding::create($request->validated());

        return WithholdingResource::make($withholding)->response()->setStatusCode(201);
    }

    public function show(Withholding $withholding): WithholdingResource
    {
        return WithholdingResource::make($withholding);
    }

    public function update(WithholdingRequest $request, Withholding $withholding): WithholdingResource
    {
        $withholding->update($request->validated());

        return WithholdingResource::make($withholding);
    }

    /**
     * Delete a withholding that no document uses. Defaults cannot be deleted.
     */
    public function destroy(Withholding $withholding): Response
    {
        abort_if($withholding->is_system, 403, 'Default withholdings cannot be deleted. Deactivate them instead.');

        Deletion::deleteOrConflict($withholding, 'This withholding is used by documents. Deactivate it instead.');

        return response()->noContent();
    }
}
