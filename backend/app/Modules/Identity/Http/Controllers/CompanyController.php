<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\CreateCompany;
use App\Modules\Identity\Http\Requests\StoreCompanyRequest;
use App\Modules\Identity\Http\Requests\UpdateCompanyRequest;
use App\Modules\Identity\Http\Resources\CompanyResource;
use App\Modules\Identity\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Companies of the signed-in user. Only owners and admins can edit a company.
 */
final class CompanyController
{
    /**
     * Companies the signed-in user belongs to.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CompanyResource::collection($request->user()->companies()->get());
    }

    /**
     * Create a company. The signed-in user becomes its owner.
     */
    public function store(StoreCompanyRequest $request, CreateCompany $createCompany): JsonResponse
    {
        $company = $createCompany($request->user(), $request->validated());

        return CompanyResource::make($request->user()->companies()->findOrFail($company->getKey()))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * A company the signed-in user belongs to.
     */
    public function show(Company $company): CompanyResource
    {
        return CompanyResource::make($company);
    }

    /**
     * Update a company. The check digit is computed again from the NIT.
     */
    public function update(UpdateCompanyRequest $request, Company $company): CompanyResource
    {
        $company->update($request->validated());

        return CompanyResource::make($company->load('municipality.department'));
    }
}
