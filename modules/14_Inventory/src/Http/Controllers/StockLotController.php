<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Requests\AddLotComponentRequest;
use Modules\Inventory\Http\Requests\StoreStockLotRequest;
use Modules\Inventory\Http\Requests\WmsQueryRequest;
use Modules\Inventory\Http\Resources\StockLotResource;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\LotRecallService;
use Modules\Inventory\Services\LotTraceabilityService;
use Modules\Inventory\Services\StockAllocationPlanner;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Lots', description: 'Batches with expiry, quarantine, two-level genealogy and certification references')]
final class StockLotController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/lots',
        summary: 'List lots',
        description: 'Lots with their expiry and certification references.',
        tags: ['Inventory Lots'],
        parameters: [
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lot_level', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['raw_material', 'packaged'])),
            new OA\Parameter(name: 'is_quarantined', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'expiring_within_days', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated lots', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLot'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List lots', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $query = StockLot::query()->orderByRaw('expiration_date ASC NULLS LAST')->orderBy('id');

        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }

        if ($request->filled('lot_level')) {
            $query->where('lot_level', $request->query('lot_level'));
        }

        if ($request->has('is_quarantined')) {
            $query->where('is_quarantined', $request->boolean('is_quarantined'));
        }

        if ($request->filled('expiring_within_days')) {
            $query->whereNotNull('expiration_date')->whereDate('expiration_date', '<=', CarbonImmutable::today()->addDays((int) $request->query('expiring_within_days')));
        }

        return response()->json(StockLotResource::collection($query->paginate((int) $request->query('per_page', 25)))->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/lots',
        summary: 'Create a lot',
        description: 'Creates a raw material or packaged lot. Without an expiry date, the shelf life of the item counts from the manufacturing date, or from the receipt date.',
        tags: ['Inventory Lots'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InventoryLotRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Lot created', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLot')),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a lot', tags: ['Inventory'])]
    public function store(StoreStockLotRequest $request): JsonResponse
    {
        $item = StockItem::findOrFail($request->string('stock_item_id')->value());
        $attributes = $request->validated();
        $attributes['company_id'] = $item->company_id;
        $attributes['lot_level'] ??= 'raw_material';
        $attributes['received_at'] ??= CarbonImmutable::today()->toDateString();

        if (empty($attributes['expiration_date']) && $item->shelf_life_days !== null) {
            $attributes['expiration_date'] = CarbonImmutable::parse($attributes['manufacturing_date'] ?? $attributes['received_at'])->addDays($item->shelf_life_days)->toDateString();
        }

        return response()->json(new StockLotResource(StockLot::create($attributes)), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/lots/stock',
        summary: 'Stock by batch',
        description: 'On-hand and reserved quantities per lot and warehouse, with certification references.',
        tags: ['Inventory Lots'],
        parameters: [
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'certification_reference', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock by batch', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryLotStock'))])),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Stock by batch', tags: ['Inventory'])]
    public function stock(Request $request): JsonResponse
    {
        $query = StockQuant::query()
            ->with(['lot', 'bin.rack.aisle.zone'])
            ->whereNotNull('lot_id')
            ->where('quantity_on_hand', '>', 0);

        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $request->query('warehouse_id')));
        }

        if ($request->filled('certification_reference')) {
            $query->whereHas('lot', static fn ($lot) => $lot->where('certification_reference', $request->query('certification_reference')));
        }

        $rows = $query->get()
            ->groupBy(static fn (StockQuant $quant): string => $quant->lot_id.'|'.$quant->bin?->rack?->aisle?->zone?->warehouse_id)
            ->map(static function ($quants): array {
                $first = $quants->first();

                return [
                    'lot_id' => $first->lot_id,
                    'lot_number' => $first->lot?->lot_number,
                    'stock_item_id' => $first->stock_item_id,
                    'warehouse_id' => $first->bin?->rack?->aisle?->zone?->warehouse_id,
                    'expiration_date' => $first->lot?->expiration_date?->toDateString(),
                    'certification_scheme' => $first->lot?->certification_scheme,
                    'certification_reference' => $first->lot?->certification_reference,
                    'certification_date' => $first->lot?->certification_date?->toDateString(),
                    'quantity_on_hand' => $quants->reduce(static fn (string $sum, StockQuant $quant): string => bcadd($sum, (string) $quant->quantity_on_hand, 4), '0'),
                    'quantity_reserved' => $quants->reduce(static fn (string $sum, StockQuant $quant): string => bcadd($sum, (string) $quant->quantity_reserved, 4), '0'),
                ];
            })
            ->values();

        return response()->json(['data' => $rows]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/lots/{id}',
        summary: 'Get a lot',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Lot', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLot')),
            new OA\Response(response: 404, description: 'Lot not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get a lot', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        return response()->json(new StockLotResource(StockLot::findOrFail($id)));
    }

    #[OA\Patch(
        path: '/api/v1/inventory/lots/{id}',
        summary: 'Update a lot',
        description: 'Corrects expiry, supplier or certification data of a lot.',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/InventoryLotRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Lot updated', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLot')),
            new OA\Response(response: 404, description: 'Lot not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Update a lot', tags: ['Inventory'])]
    public function update(string $id, StoreStockLotRequest $request): JsonResponse
    {
        $lot = StockLot::findOrFail($id);
        $lot->update($request->safe()->except(['stock_item_id']));

        return response()->json(new StockLotResource($lot));
    }

    #[OA\Post(
        path: '/api/v1/inventory/lots/{id}/components',
        summary: 'Record a component lot',
        description: 'States that this lot was made or packaged from another one, optionally on a production order.',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['component_lot_id'],
                properties: [
                    new OA\Property(property: 'component_lot_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'quantity_consumed', type: 'number'),
                    new OA\Property(property: 'production_order_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'notes', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Component recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'lot_id', type: 'string', format: 'uuid'), new OA\Property(property: 'component_lot_id', type: 'string', format: 'uuid')])),
            new OA\Response(response: 404, description: 'Lot not found'),
            new OA\Response(response: 422, description: 'Validation failed, or the link would create a loop'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Record a component lot', tags: ['Inventory'])]
    public function addComponent(string $id, AddLotComponentRequest $request, LotTraceabilityService $traceability): JsonResponse
    {
        $lot = StockLot::findOrFail($id);
        $component = StockLot::findOrFail($request->string('component_lot_id')->value());
        $link = $traceability->link($lot, $component, $request->safe()->except(['component_lot_id']));

        return response()->json(['id' => $link->id, 'lot_id' => $link->lot_id, 'component_lot_id' => $link->component_lot_id], 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/lots/{id}/traceability',
        summary: 'Trace a lot',
        description: 'The traceability list: backward from a finished lot to raw materials and suppliers, or forward from a raw lot to derived lots and their deliveries.',
        tags: ['Inventory Lots'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'direction', in: 'query', required: false, schema: new OA\Schema(type: 'string', default: 'backward', enum: ['backward', 'forward'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Traceability tree', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLotTrace')),
            new OA\Response(response: 404, description: 'Lot not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Trace a lot', tags: ['Inventory'])]
    public function traceability(string $id, Request $request, LotTraceabilityService $traceability): JsonResponse
    {
        $lot = StockLot::with('item')->findOrFail($id);

        return response()->json($request->query('direction') === 'forward' ? $traceability->forward($lot) : $traceability->backward($lot));
    }

    #[OA\Post(
        path: '/api/v1/inventory/lots/{id}/quarantine',
        summary: 'Quarantine a lot',
        description: 'Takes the lot out of reservations and picking. Units already reserved stay reserved.',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Lot quarantined', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLot')),
            new OA\Response(response: 404, description: 'Lot not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Quarantine a lot', tags: ['Inventory'])]
    public function quarantine(string $id): JsonResponse
    {
        $lot = StockLot::findOrFail($id);
        $lot->update(['is_quarantined' => true]);

        return response()->json(new StockLotResource($lot));
    }

    #[OA\Post(
        path: '/api/v1/inventory/lots/{id}/release',
        summary: 'Release a lot from quarantine',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Lot released', content: new OA\JsonContent(ref: '#/components/schemas/InventoryLot')),
            new OA\Response(response: 404, description: 'Lot not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Release a lot from quarantine', tags: ['Inventory'])]
    public function release(string $id): JsonResponse
    {
        $lot = StockLot::findOrFail($id);
        $lot->update(['is_quarantined' => false]);

        return response()->json(new StockLotResource($lot));
    }

    #[OA\Get(
        path: '/api/v1/inventory/availability',
        summary: 'Stock available to reserve',
        description: 'The quants that can serve an item in a warehouse, in the order they will be taken, and the strategy in force.',
        tags: ['Inventory Lots'],
        parameters: [
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Available stock in picking order', content: new OA\JsonContent(ref: '#/components/schemas/InventoryAvailability')),
            new OA\Response(response: 404, description: 'Item or warehouse not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Stock available to reserve', tags: ['Inventory'])]
    public function availability(Request $request, StockAllocationPlanner $planner): JsonResponse
    {
        $validated = $request->validate(['stock_item_id' => ['required', 'uuid'], 'warehouse_id' => ['required', 'uuid']]);
        $item = StockItem::findOrFail($validated['stock_item_id']);
        $warehouse = Warehouse::findOrFail($validated['warehouse_id']);

        return response()->json(['strategy' => $planner->strategyFor($item, $warehouse)->value, 'quants' => $planner->availability($item, $warehouse)]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/lots/{id}/recall',
        operationId: 'wmsLotRecallReport',
        summary: 'Recall report of a lot',
        description: 'Forward trace of the lot through its genealogy: every affected lot with the stock still on hand (per bin and warehouse), the delivery notes and customers that received it (by batch number on the DDT lines and by the lot of the stock issued for a DDT) and the other documents that took stock of it. Read-only.',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Recall report', content: new OA\JsonContent(ref: '#/components/schemas/WmsLotRecall')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
        ]
    )]
    public function recallReport(string $id, LotRecallService $recall): JsonResponse
    {
        return response()->json($recall->report(StockLot::query()->findOrFail($id)));
    }

    #[OA\Post(
        path: '/api/v1/inventory/lots/{id}/recall',
        operationId: 'wmsLotRecall',
        summary: 'Recall a lot',
        description: 'Same report as the GET, and with quarantine=true (default) every affected lot is quarantined at once: its stock can no longer be reserved, picked, shipped or sold until released.',
        tags: ['Inventory Lots'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/WmsRecordId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'quarantine', type: 'boolean', example: true),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Recall report, lots quarantined', content: new OA\JsonContent(ref: '#/components/schemas/WmsLotRecall')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function recall(string $id, WmsQueryRequest $request, LotRecallService $recall): JsonResponse
    {
        return response()->json($recall->report(StockLot::query()->findOrFail($id), $request->boolean('quarantine', true)));
    }
}
