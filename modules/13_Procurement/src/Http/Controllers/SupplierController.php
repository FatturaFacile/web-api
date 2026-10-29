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
use Modules\Procurement\Http\Requests\UpdateSupplierRequest;
use Modules\Procurement\Http\Resources\SupplierResource;
use Modules\Procurement\Models\Supplier;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Suppliers', description: 'Vendor master data management, qualification and compliance')]
final class SupplierController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/suppliers',
        summary: 'List procurement suppliers and approved vendors',
        description: 'Retrieve a paginated list of enterprise suppliers with multi-attribute filtering and sorting.',
        tags: ['Procurement Suppliers'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'search', in: 'query', description: 'Search term for company name, code or VAT', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'avl_only', in: 'query', description: 'Filter only Approved Vendor List (AVL) records', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'category_id', in: 'query', description: 'Filter by supplier category UUID', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'sort', in: 'query', description: 'Sort field (prefix - for descending, e.g. -created_at)', required: false, schema: new OA\Schema(type: 'string', default: 'company_name')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated supplier list', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ProcurementSupplier')),
                    new OA\Property(property: 'meta', ref: '#/components/schemas/ProcurementApiPaginationMeta'),
                ]
            )),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List procurement suppliers and approved vendors', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supplier::class);

        $query = Supplier::with(['category', 'contacts']);

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->value().'%';
            $query->where(function ($q) use ($search): void {
                $q->where('company_name', 'like', $search)
                    ->orWhere('supplier_code', 'like', $search)
                    ->orWhere('vat_number', 'like', $search);
            });
        }

        if ($request->boolean('avl_only')) {
            $query->where('is_approved_vendor', true);
        }

        if ($request->filled('category_id')) {
            $query->where('supplier_category_id', $request->input('category_id'));
        }

        $sort = $request->query('sort', 'company_name');
        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        if (in_array($column, ['company_name', 'supplier_code', 'created_at', 'rating', 'credit_limit'], true)) {
            $query->orderBy($column, $direction);
        } else {
            $query->orderBy('company_name', 'asc');
        }

        $suppliers = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(SupplierResource::collection($suppliers)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/procurement/suppliers',
        summary: 'Register a new supplier or vendor',
        description: 'Creates a new supplier in the enterprise tenant vendor master.',
        tags: ['Procurement Suppliers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['supplier_code', 'company_name'],
                properties: [
                    new OA\Property(property: 'supplier_code', type: 'string', example: 'SUP-0001'),
                    new OA\Property(property: 'company_name', type: 'string', example: 'Acme Industrial Supplies GmbH'),
                    new OA\Property(property: 'vat_number', type: 'string', example: 'IT12345678901'),
                    new OA\Property(property: 'fiscal_code', type: 'string', example: '12345678901'),
                    new OA\Property(property: 'supplier_category_id', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'currency', type: 'string', example: 'EUR'),
                    new OA\Property(property: 'payment_terms_code', type: 'string', example: 'NET30'),
                    new OA\Property(property: 'incoterms', type: 'string', example: 'DAP'),
                    new OA\Property(property: 'credit_limit', type: 'number', example: 50000.00),
                    new OA\Property(property: 'rating', type: 'string', example: 'A', enum: ['A', 'B', 'C', 'D']),
                    new OA\Property(property: 'is_approved_vendor', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Supplier registered', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSupplier')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiValidationErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/procurement/suppliers/{id}',
        summary: 'Show supplier details and performance profile',
        description: 'Retrieve a single supplier with contracts, scorecard, and contact information.',
        tags: ['Procurement Suppliers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Supplier UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier details', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSupplier')),
            new OA\Response(response: 404, description: 'Supplier not found', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Show supplier details and performance profile', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $supplier = Supplier::with(['category', 'contacts', 'catalogs', 'contracts', 'evaluations', 'scorecards'])->findOrFail($id);
        $this->authorize('view', $supplier);

        return response()->json(new SupplierResource($supplier));
    }

    #[OA\Put(
        path: '/api/v1/procurement/suppliers/{id}',
        summary: 'Update existing supplier',
        description: 'Updates vendor master data, ratings, financial terms, or approved status.',
        tags: ['Procurement Suppliers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Supplier UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSupplier')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Supplier updated', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementSupplier')),
            new OA\Response(response: 404, description: 'Supplier not found', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update existing supplier', tags: ['Procurement'])]
    public function update(UpdateSupplierRequest $request, string $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $this->authorize('update', $supplier);

        $supplier->update($request->validated());

        return response()->json(new SupplierResource($supplier->fresh(['category', 'contacts'])));
    }

    #[OA\Delete(
        path: '/api/v1/procurement/suppliers/{id}',
        summary: 'Soft-delete a supplier',
        description: 'Soft-deletes the supplier record if not locked by active workflow or active POs.',
        tags: ['Procurement Suppliers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Supplier UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier deleted successfully', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string', example: 'Supplier deleted successfully')]
            )),
            new OA\Response(response: 404, description: 'Supplier not found', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
            new OA\Response(response: 403, description: 'Supplier is locked by active workflow', content: new OA\JsonContent(ref: '#/components/schemas/ProcurementApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Soft-delete a supplier', tags: ['Procurement'])]
    public function destroy(string $id): JsonResponse
    {
        $supplier = Supplier::findOrFail($id);
        $this->authorize('delete', $supplier);

        $supplier->delete();

        return response()->json(['message' => 'Supplier deleted successfully']);
    }
}
