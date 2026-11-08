<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Http\Requests\StoreWarehouseLocationRequest;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\WarehouseLocationService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Warehouses', description: 'Warehouse master data and locations')]
final class WarehouseLocationController extends Controller
{
    #[OA\Post(
        path: '/api/v1/inventory/warehouses/{id}/locations',
        summary: 'Add a warehouse location',
        description: 'Adds a bin, creating its zone, aisle and rack when missing. A zone of type quarantine or transit keeps its stock out of reservations and picking.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['zone_code', 'aisle_code', 'rack_code', 'bin_code', 'barcode'],
                properties: [
                    new OA\Property(property: 'zone_code', type: 'string', example: 'Z1'),
                    new OA\Property(property: 'zone_name', type: 'string'),
                    new OA\Property(property: 'zone_type', type: 'string', enum: ['receiving', 'storage', 'picking', 'packing', 'shipping', 'quarantine', 'transit']),
                    new OA\Property(property: 'aisle_code', type: 'string', example: 'A1'),
                    new OA\Property(property: 'rack_code', type: 'string', example: 'R1'),
                    new OA\Property(property: 'bin_code', type: 'string', example: 'B01'),
                    new OA\Property(property: 'barcode', type: 'string', example: 'BIN-A1-R1-B01'),
                    new OA\Property(property: 'pick_sequence', type: 'integer', description: 'Explicit position on the picking route; overrides the aisle walk'),
                    new OA\Property(property: 'max_weight_kg', type: 'number'),
                    new OA\Property(property: 'max_volume_cbm', type: 'number'),
                    new OA\Property(property: 'max_quantity', type: 'number', description: 'Maximum units the bin holds, checked by putaway'),
                    new OA\Property(property: 'bin_type', type: 'string', enum: WarehouseBin::BIN_TYPES, description: 'Default: the natural type of the zone (storage for a storage zone)'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Location created', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLocation')),
            new OA\Response(response: 404, description: 'Warehouse not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Add a warehouse location', tags: ['Inventory'])]
    public function store(string $id, StoreWarehouseLocationRequest $request, WarehouseLocationService $locations): JsonResponse
    {
        $warehouse = Warehouse::findOrFail($id);
        $bin = $locations->addBin($warehouse, $request->validated());

        return response()->json([
            'id' => $bin->id,
            'warehouse_id' => $warehouse->id,
            'code' => $bin->code,
            'barcode' => $bin->barcode,
            'pick_sequence' => $bin->pick_sequence,
            'bin_type' => $bin->bin_type,
            'max_quantity' => $bin->max_quantity,
        ], 201);
    }
}
