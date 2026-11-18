<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Listing;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Http\Requests\NumberTemplateRequest;
use App\Modules\Billing\Http\Resources\NumberTemplateResource;
use App\Modules\Billing\Models\NumberTemplate;
use App\Support\Deletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Numbering ranges of the current company. Consecutive numbers are not assigned yet (stage 3).
 */
final class NumberTemplateController
{
    /**
     * Ranges by document type, the default first; ?search= matches the prefix and the resolution.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = NumberTemplate::query()
            ->orderBy('document_type')
            ->orderByDesc('is_default')
            ->orderBy('prefix')
            ->orderBy('from_number');

        return NumberTemplateResource::collection(Listing::paginate($query, $request, ['prefix', 'resolution_number']));
    }

    /**
     * Create a range. Marking it as default unmarks the previous default of its document type.
     */
    public function store(NumberTemplateRequest $request): JsonResponse
    {
        $template = DB::transaction(function () use ($request): NumberTemplate {
            $template = new NumberTemplate($request->validated());

            if ($template->is_default) {
                $this->clearDefault($template->document_type);
            }

            $template->save();

            return $template;
        });

        return NumberTemplateResource::make($template->refresh())->response()->setStatusCode(201);
    }

    public function show(NumberTemplate $numberTemplate): NumberTemplateResource
    {
        return NumberTemplateResource::make($numberTemplate);
    }

    public function update(NumberTemplateRequest $request, NumberTemplate $numberTemplate): NumberTemplateResource
    {
        DB::transaction(function () use ($request, $numberTemplate): void {
            $numberTemplate->fill($request->validated());

            if ($numberTemplate->is_default) {
                $this->clearDefault($numberTemplate->document_type, except: $numberTemplate);
            }

            $numberTemplate->save();
        });

        return NumberTemplateResource::make($numberTemplate->refresh());
    }

    /**
     * Delete a range that no document uses; otherwise deactivate it instead.
     */
    public function destroy(NumberTemplate $numberTemplate): Response
    {
        Deletion::deleteOrConflict($numberTemplate, 'This numbering is used by documents. Deactivate it instead.');

        return response()->noContent();
    }

    private function clearDefault(DocumentType $type, ?NumberTemplate $except = null): void
    {
        NumberTemplate::query()
            ->where('document_type', $type)
            ->where('is_default', true)
            ->when($except, fn ($query, NumberTemplate $template) => $query->whereKeyNot($template->getKey()))
            ->update(['is_default' => false]);
    }
}
