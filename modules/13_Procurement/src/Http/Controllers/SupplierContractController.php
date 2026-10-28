<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Http\Requests\StoreSupplierContractRequest;
use Modules\Procurement\Http\Requests\UpdateSupplierContractRequest;
use Modules\Procurement\Http\Resources\SupplierContractResource;
use Modules\Procurement\Models\SupplierContract;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Procurement Contracts', description: 'Supplier framework agreements, commitments, and master terms')]
final class SupplierContractController extends Controller
{
    #[OA\Get(
        path: '/api/v1/procurement/contracts',
        summary: 'List supplier framework agreements and master contracts',
        description: 'Retrieve paginated master contracts and framework agreements.',
        tags: ['Procurement Contracts'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Page number', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Items per page', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'supplier_id', in: 'query', description: 'Filter by supplier UUID', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter by status (draft, active, expired)', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated contracts list'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List supplier framework agreements and master contracts', tags: ['Procurement'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupplierContract::class);

        $query = SupplierContract::with('supplier')
            ->orderBy('ends_at', 'asc');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->input('supplier_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $contracts = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(SupplierContractResource::collection($contracts)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/procurement/contracts',
        summary: 'Create a supplier framework contract',
        description: 'Records a new framework agreement or master contract for a vendor.',
        tags: ['Procurement Contracts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 201, description: 'Contract created'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a supplier framework contract', tags: ['Procurement'])]
    public function store(StoreSupplierContractRequest $request): JsonResponse
    {
        $this->authorize('create', SupplierContract::class);

        $contract = SupplierContract::create(array_merge($request->validated(), [
            'status' => $request->input('status', 'draft'),
            'created_by' => auth()->id(),
        ]));

        return response()->json(new SupplierContractResource($contract->fresh('supplier')), 201);
    }

    #[OA\Get(
        path: '/api/v1/procurement/contracts/{id}',
        summary: 'Show supplier contract details',
        description: 'Retrieve full contract terms, validity dates, spend consumption, and orders.',
        tags: ['Procurement Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Contract UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contract details'),
            new OA\Response(response: 404, description: 'Contract not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Show supplier contract details', tags: ['Procurement'])]
    public function show(string $id): JsonResponse
    {
        $contract = SupplierContract::with(['supplier', 'purchaseOrders'])->findOrFail($id);
        $this->authorize('view', $contract);

        return response()->json(new SupplierContractResource($contract));
    }

    #[OA\Put(
        path: '/api/v1/procurement/contracts/{id}',
        summary: 'Update supplier contract',
        description: 'Update contract dates, committed amounts, or status.',
        tags: ['Procurement Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Contract UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Contract updated'),
            new OA\Response(response: 404, description: 'Contract not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update supplier contract', tags: ['Procurement'])]
    public function update(UpdateSupplierContractRequest $request, string $id): JsonResponse
    {
        $contract = SupplierContract::findOrFail($id);
        $this->authorize('update', $contract);

        $contract->update($request->validated());

        return response()->json(new SupplierContractResource($contract->fresh('supplier')));
    }

    #[OA\Delete(
        path: '/api/v1/procurement/contracts/{id}',
        summary: 'Delete supplier contract',
        description: 'Soft-deletes a framework contract if not locked.',
        tags: ['Procurement Contracts'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'Contract UUID', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Contract deleted'),
            new OA\Response(response: 404, description: 'Contract not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete supplier contract', tags: ['Procurement'])]
    public function destroy(string $id): JsonResponse
    {
        $contract = SupplierContract::findOrFail($id);
        $this->authorize('delete', $contract);

        $contract->delete();

        return response()->json(['message' => 'Supplier contract deleted successfully']);
    }
}
