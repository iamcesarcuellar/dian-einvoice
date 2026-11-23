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
     * List numbering ranges.
     *
     * Sorted by document type, the default first. ?search= matches the prefix and the resolution.
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
     * Create a numbering range.
     *
     * Marking it as the default unmarks the previous default for its document type. Owners and
     * admins, in the web app; API keys get 403.
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

    /**
     * Show a numbering range.
     */
    public function show(NumberTemplate $numberTemplate): NumberTemplateResource
    {
        return NumberTemplateResource::make($numberTemplate);
    }

    /**
     * Update a numbering range.
     *
     * Owners and admins, in the web app; API keys get 403.
     */
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
     * Delete a numbering range.
     *
     * A range that a document uses is deactivated instead. Owners and admins, in the web app; API
     * keys get 403.
     */
    public function destroy(NumberTemplate $numberTemplate): Response
    {
        DB::transaction(function () use ($numberTemplate): void {
            Deletion::deleteOrConflict($numberTemplate, __('billing::errors.numbering.in_use'));
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
