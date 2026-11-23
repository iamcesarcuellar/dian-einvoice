<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Listing;
use App\Modules\Catalog\Http\Requests\ItemRequest;
use App\Modules\Catalog\Http\Resources\ItemResource;
use App\Modules\Catalog\Models\Item;
use App\Support\Deletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Products and services of the current company.
 */
final class ItemController
{
    /**
     * List items.
     *
     * Sorted by name. ?search= matches the code and the name; ?active=1 leaves out inactive items.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Item::query()
            ->with('tax')
            ->when($request->boolean('active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name');

        return ItemResource::collection(Listing::paginate($query, $request, ['code', 'name']));
    }

    /**
     * Create an item.
     */
    public function store(ItemRequest $request): JsonResponse
    {
        $item = Item::create($request->validated());

        return ItemResource::make($item->load('tax'))->response()->setStatusCode(201);
    }

    /**
     * Show an item.
     */
    public function show(Item $item): ItemResource
    {
        return ItemResource::make($item->load('tax'));
    }

    /**
     * Update an item.
     */
    public function update(ItemRequest $request, Item $item): ItemResource
    {
        $item->update($request->validated());

        return ItemResource::make($item->load('tax'));
    }

    /**
     * Delete an item.
     *
     * An item that a document uses is deactivated instead.
     */
    public function destroy(Item $item): Response
    {
        Deletion::deleteOrConflict($item, __('catalog::errors.item_in_use'));

        return response()->noContent();
    }
}
