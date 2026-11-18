<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Listing;
use App\Modules\Billing\Enums\NumberingType;
use App\Modules\Billing\Http\Requests\NumberTemplateRequest;
use App\Modules\Billing\Http\Resources\NumberTemplateResource;
use App\Modules\Billing\Models\NumberTemplate;
use App\Modules\Dian\Pipeline\ChainEvents;
use App\Modules\Identity\Tenancy\CurrentCompany;
use App\Support\Deletion;
use App\Support\Outbox\Outbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Numbering ranges of the current company. Every change registers the company again with the local
 * DIAN simulator (event dian.taxpayer_changed), so it knows the ranges and their technical keys.
 */
final class NumberTemplateController
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly CurrentCompany $currentCompany,
    ) {}

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
            $this->taxpayerChanged();

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
            $this->taxpayerChanged();
        });

        return NumberTemplateResource::make($numberTemplate->refresh());
    }

    /**
     * Delete a range that no document uses; otherwise deactivate it instead.
     */
    public function destroy(NumberTemplate $numberTemplate): Response
    {
        DB::transaction(function () use ($numberTemplate): void {
            Deletion::deleteOrConflict($numberTemplate, 'This numbering is used by documents. Deactivate it instead.');
            $this->taxpayerChanged();
        });

        return response()->noContent();
    }

    private function taxpayerChanged(): void
    {
        $this->outbox->record($this->currentCompany->id(), 'company', $this->currentCompany->id(), ChainEvents::TAXPAYER_CHANGED);
    }

    private function clearDefault(NumberingType $type, ?NumberTemplate $except = null): void
    {
        NumberTemplate::query()
            ->where('document_type', $type)
            ->where('is_default', true)
            ->when($except, fn ($query, NumberTemplate $template) => $query->whereKeyNot($template->getKey()))
            ->update(['is_default' => false]);
    }
}
