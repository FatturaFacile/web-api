<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Actions\CreateSupplierAction;
use Modules\Procurement\DTOs\CreateSupplierDTO;
use Modules\Procurement\Http\Requests\StoreSupplierRequest;
use Modules\Procurement\Http\Resources\SupplierResource;
use Modules\Procurement\Models\Supplier;

final class SupplierController extends Controller
{
    #[EnterpriseEndpoint(summary: 'List procurement suppliers and approved vendors', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $query = Supplier::with(['category', 'contacts'])
            ->orderBy('company_name', 'asc');

        if ($request->boolean('avl_only')) {
            $query->where('is_approved_vendor', true);
        }

        if ($request->filled('category_id')) {
            $query->where('supplier_category_id', $request->input('category_id'));
        }

        $suppliers = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(SupplierResource::collection($suppliers)->response()->getData(true));
    }

    #[EnterpriseEndpoint(summary: 'Register a new supplier or vendor', tags: ['Procurement'])]
    public function store(StoreSupplierRequest $request, CreateSupplierAction $action): JsonResponse
    {
        $this->authorize('create', Supplier::class);

        $dto = new CreateSupplierDTO(
            supplierCode: $request->string('supplier_code')->value(),
            companyName: $request->string('company_name')->value(),
            vatNumber: $request->input('vat_number'),
            fiscalCode: $request->input('fiscal_code'),
            categoryId: $request->input('supplier_category_id'),
            contactId: $request->input('contact_id'),
            currency: $request->string('currency', 'EUR')->value(),
            paymentTermsCode: $request->input('payment_terms_code'),
            incoterms: $request->input('incoterms'),
            creditLimit: (float) $request->input('credit_limit', 0.0),
            rating: $request->string('rating', 'B')->value(),
            isApprovedVendor: $request->boolean('is_approved_vendor'),
            metadata: (array) $request->input('metadata', []),
            creatorUserId: (string) auth()->id()
        );

        $supplier = $action->execute($dto);

        return response()->json(new SupplierResource($supplier), 201);
    }

    #[EnterpriseEndpoint(summary: 'Show supplier details and performance profile', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $supplier = Supplier::with(['category', 'contacts', 'catalogs', 'contracts', 'evaluations', 'scorecards'])->findOrFail($id);
        $this->authorize('view', $supplier);

        return response()->json(new SupplierResource($supplier));
    }
}
