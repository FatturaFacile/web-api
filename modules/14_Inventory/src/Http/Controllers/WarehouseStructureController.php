<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\UpdateWarehouseBinRequest;
use Modules\Inventory\Http\Requests\UpdateWarehouseZoneRequest;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Inventory\Services\WarehouseCapacityService;
use OpenApi\Attributes as OA;

/**
 * Warehouse location master: zones and bins with their type, capacities and current occupancy.
 */
final class WarehouseStructureController extends Controller
{
    /**
     * The zones of a warehouse with the number of their bins.
     */
    #[OA\Get(
        path: '/api/v1/inventory/warehouses/{id}/zones',
        operationId: 'wmsListWarehouseZones',
        summary: 'List the zones of a warehouse',
        description: 'Zones (receiving, storage, picking, packing, shipping, quarantine, transit) of the warehouse with the number of bins in each. Stock in quarantine and transit zones is never reserved, picked or sold.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Zones of the warehouse', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsZone'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function zones(string $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);

        $zones = WarehouseZone::query()
            ->where('warehouse_id', $warehouse->id)
            ->orderBy('code')
            ->get()
            ->map(static fn (WarehouseZone $zone): array => self::zone($zone));

        return response()->json(['data' => $zones->values()->all()]);
    }

    #[OA\Patch(
        path: '/api/v1/inventory/zones/{id}',
        operationId: 'wmsUpdateWarehouseZone',
        summary: 'Update a warehouse zone',
        description: 'Renames a zone or changes its type. Turning a zone into quarantine or transit takes its stock out of reservations, picks and sales at once.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', example: 'Scaffalature alte'),
            new OA\Property(property: 'zone_type', type: 'string', enum: UpdateWarehouseZoneRequest::ZONE_TYPES, example: 'storage'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Zone updated', content: new OA\JsonContent(ref: '#/components/schemas/WmsZone')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function updateZone(string $id, UpdateWarehouseZoneRequest $request): JsonResponse
    {
        $zone = WarehouseZone::query()->findOrFail($id);
        $zone->update($request->validated());

        return response()->json(self::zone($zone->fresh() ?? $zone));
    }

    #[OA\Get(
        path: '/api/v1/inventory/warehouses/{id}/bins',
        operationId: 'wmsListWarehouseBins',
        summary: 'List the bins of a warehouse with occupancy',
        description: 'Bin master of the warehouse: location path, bin type, capacities (units, kg, m3; empty = not checked) and current occupancy computed from the stock quants (units, kg and m3 from the item master weights and volumes).',
        tags: ['Inventory Warehouses'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsRecordId'),
            new OA\Parameter(name: 'zone_id', description: 'Only the bins of this zone', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'bin_type', description: 'Only bins of this type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: WarehouseBin::BIN_TYPES)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Bins of the warehouse', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WmsBin'))])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function bins(string $id, Request $request, WarehouseCapacityService $capacity): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);

        $bins = WarehouseBin::query()
            ->with('rack.aisle.zone')
            ->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouse->id))
            ->when($request->filled('zone_id'), static fn ($query) => $query->whereHas('rack.aisle', static fn ($aisle) => $aisle->where('warehouse_zone_id', (string) $request->query('zone_id'))))
            ->when($request->filled('bin_type'), static fn ($query) => $query->where('bin_type', (string) $request->query('bin_type')))
            ->orderBy('code')
            ->get();

        return response()->json(['data' => $capacity->describe($bins)]);
    }

    #[OA\Patch(
        path: '/api/v1/inventory/bins/{id}',
        operationId: 'wmsUpdateWarehouseBin',
        summary: 'Update a bin',
        description: 'Changes the bin type, the capacities checked by putaway (units, kg, m3; null removes a limit), the lock (a locked bin is skipped by reservations, picks and putaway) and the position on the picking route.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'bin_type', type: 'string', enum: WarehouseBin::BIN_TYPES, example: 'picking'),
            new OA\Property(property: 'max_quantity', type: 'number', nullable: true, example: 200),
            new OA\Property(property: 'max_weight_kg', type: 'number', nullable: true, example: 500),
            new OA\Property(property: 'max_volume_cbm', type: 'number', nullable: true, example: 1.2),
            new OA\Property(property: 'is_locked', type: 'boolean', example: false),
            new OA\Property(property: 'pick_sequence', type: 'integer', nullable: true, example: 10),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Bin updated', content: new OA\JsonContent(ref: '#/components/schemas/WmsBin')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function updateBin(string $id, UpdateWarehouseBinRequest $request, WarehouseCapacityService $capacity): JsonResponse
    {
        $bin = WarehouseBin::query()->findOrFail($id);
        $bin->update($request->validated());

        return response()->json($capacity->describe(WarehouseBin::query()->with('rack.aisle.zone')->whereKey($bin->id)->get())[0]);
    }

    /**
     * @return array{id: string, warehouse_id: string, code: string, name: string, zone_type: string, bins_count: int}
     */
    private static function zone(WarehouseZone $zone): array
    {
        return [
            'id' => (string) $zone->id,
            'warehouse_id' => (string) $zone->warehouse_id,
            'code' => (string) $zone->code,
            'name' => (string) $zone->name,
            'zone_type' => (string) $zone->zone_type,
            'bins_count' => WarehouseBin::query()->whereHas('rack.aisle', static fn ($aisle) => $aisle->where('warehouse_zone_id', $zone->id))->count(),
        ];
    }
}
