<?php

namespace App\Modules\Reference\Http\Controllers;

use App\Http\Listing;
use App\Modules\Reference\Enums\CreditNoteConcept;
use App\Modules\Reference\Enums\DebitNoteConcept;
use App\Modules\Reference\Enums\FiscalResponsibility;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\PaymentForm;
use App\Modules\Reference\Enums\PaymentMeans;
use App\Modules\Reference\Enums\TaxScheme;
use App\Modules\Reference\Enums\TaxType;
use App\Modules\Reference\Enums\UnitOfMeasure;
use App\Modules\Reference\Enums\WithholdingType;
use App\Modules\Reference\Http\Resources\MunicipalityResource;
use App\Modules\Reference\Models\Department;
use App\Modules\Reference\Models\Municipality;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reference data: DIAN code lists (technical annex 1.9) and DANE departments and municipalities.
 */
final class ReferenceController
{
    /**
     * Every code list in one response, with English names.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'identification_types' => IdentificationType::options(),
                'organization_types' => OrganizationType::options(),
                'fiscal_responsibilities' => FiscalResponsibility::options(),
                'tax_schemes' => TaxScheme::options(),
                'tax_types' => TaxType::options(),
                'withholding_types' => array_map(fn (WithholdingType $type): array => [
                    'code' => $type->value,
                    'name' => $type->label(),
                    'base' => $type->base()->value,
                ], WithholdingType::cases()),
                'units' => UnitOfMeasure::options(),
                'payment_forms' => PaymentForm::options(),
                'payment_means' => PaymentMeans::options(),
                // Concepts of credit and debit notes, from the official lists of the toolbox.
                'credit_note_concepts' => CreditNoteConcept::reference(),
                'debit_note_concepts' => DebitNoteConcept::reference(),
                'departments' => Department::query()->orderBy('name')->get(['code', 'name']),
            ],
        ]);
    }

    /**
     * Municipalities, optionally of one department (?department=05), searched by name or code.
     */
    public function municipalities(Request $request): AnonymousResourceCollection
    {
        $request->validate(['department' => ['sometimes', 'nullable', 'string', 'size:2']]);

        $query = Municipality::query()
            ->with('department')
            ->when($request->filled('department'), fn ($query) => $query->where('department_code', $request->string('department')->value()))
            ->orderBy('name');

        return MunicipalityResource::collection(Listing::paginate($query, $request, ['name', 'code']));
    }
}
