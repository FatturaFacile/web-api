<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\StoreWarehouseRequest;
use Modules\Inventory\Http\Requests\UpdateWarehouseRequest;
use Modules\Inventory\Http\Resources\WarehouseResource;
use Modules\Inventory\Models\Warehouse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Warehouses', description: 'Warehouse definitions, logistics centers, service van locations, and zones')]
final class WarehouseController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/warehouses',
        summary: 'List warehouses',
        description: 'Retrieve paginated list of warehouses with optional type filtering.',
        tags: ['Inventory Warehouses'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['standard', 'van', 'transit', 'quarantine', 'consignment'])),
            new OA\Parameter(name: 'is_active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of warehouses'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List warehouses', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Warehouse::class);

        $query = Warehouse::with(['zones'])->orderBy('code');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $warehouses = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(WarehouseResource::collection($warehouses)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/warehouses',
        summary: 'Create a warehouse',
        description: 'Creates a new physical or virtual warehouse location.',
        tags: ['Inventory Warehouses'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name', 'type'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'WH-MAIN'),
                    new OA\Property(property: 'name', type: 'string', example: 'Milano Central Logistics Hub'),
                    new OA\Property(property: 'type', type: 'string', enum: ['standard', 'van', 'transit', 'quarantine', 'consignment'], example: 'standard'),
                    new OA\Property(property: 'address', type: 'string', example: 'Via Logistica 10'),
                    new OA\Property(property: 'city', type: 'string', example: 'Milano'),
                    new OA\Property(property: 'postal_code', type: 'string', example: '20100'),
                    new OA\Property(property: 'country', type: 'string', example: 'IT'),
                    new OA\Property(property: 'is_active', type: 'boolean', example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Warehouse created'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a warehouse', tags: ['Inventory'])]
    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $this->authorize('create', Warehouse::class);

        $warehouse = Warehouse::create($request->validated());

        return response()->json(new WarehouseResource($warehouse), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/warehouses/{id}',
        summary: 'Get warehouse details',
        description: 'Retrieve detailed information of a specific warehouse including zones and bins.',
        tags: ['Inventory Warehouses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Warehouse details'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get warehouse details', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        $warehouse = Warehouse::with(['zones.aisles.racks.bins'])->findOrFail($id);
        $this->authorize('view', $warehouse);

        return response()->json(new WarehouseResource($warehouse));
    }

    #[OA\Put(
        path: '/api/v1/inventory/warehouses/{id}',
        summary: 'Update warehouse',
        description: 'Updates configuration, address, or manager of a warehouse.',
        tags: ['Inventory Warehouses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'address', type: 'string'),
                    new OA\Property(property: 'city', type: 'string'),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Warehouse updated'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update warehouse', tags: ['Inventory'])]
    public function update(string $id, UpdateWarehouseRequest $request): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);
        $this->authorize('update', $warehouse);

        $warehouse->update($request->validated());

        return response()->json(new WarehouseResource($warehouse));
    }

    #[OA\Delete(
        path: '/api/v1/inventory/warehouses/{id}',
        summary: 'Delete warehouse',
        description: 'Deactivates or deletes an empty warehouse.',
        tags: ['Inventory Warehouses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Warehouse deleted'),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Delete warehouse', tags: ['Inventory'])]
    public function destroy(string $id): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);
        $this->authorize('delete', $warehouse);

        $warehouse->delete();

        return response()->json(null, 204);
    }
}
