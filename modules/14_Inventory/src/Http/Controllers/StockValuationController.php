<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Http\Requests\Concerns\ValidatesTenantRecords;
use Modules\Inventory\Http\Requests\WmsQueryRequest;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Services\InventoryKpiService;
use Modules\Inventory\Services\StockValuationReportService;
use OpenApi\Attributes as OA;

/**
 * Stock valuation (current and at a past date), daily valuation snapshots and the warehouse KPIs.
 */
#[OA\Tag(name: 'Inventory Valuation', description: 'Stock valuation at a date (moving average, FIFO, standard cost), snapshots and warehouse KPIs')]
final class StockValuationController extends Controller
{
    use ValidatesTenantRecords;

    /** Method names of the summary endpoint and the valuation methods of the module. */
    private const array SUMMARY_METHODS = ['FIFO' => 'fifo', 'AVCO' => 'moving_average', 'STANDARD' => 'standard_cost'];

    #[OA\Get(
        path: '/api/v1/inventory/valuation/summary',
        summary: 'Get the current stock valuation',
        description: 'Current value of the stock, per item, of all warehouses or of one, with the given method for every item (FIFO = latest receipts, AVCO = ledger moving average, STANDARD = standard cost). Superseded by GET /api/v1/inventory/valuation/at-date, which values each item with the method of its category and works for any past date.',
        deprecated: true,
        tags: ['Inventory Valuation'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
            new OA\Parameter(name: 'valuation_method', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['FIFO', 'AVCO', 'STANDARD'], default: 'FIFO')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valuation summary', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'valuation_method', type: 'string', example: 'FIFO'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
                new OA\Property(property: 'total_valuation', type: 'string', example: '15230.50'),
                new OA\Property(property: 'items_count', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(properties: [
                    new OA\Property(property: 'item_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'sku', type: 'string'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'valuation', type: 'string', example: '120.00'),
                ])),
            ])),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get total inventory valuation summary', tags: ['Inventory'])]
    public function summary(Request $request, StockValuationReportService $report): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid', $this->tenantExists('warehouses')],
            'valuation_method' => ['nullable', 'string', Rule::in(array_keys(self::SUMMARY_METHODS))],
        ]);
        $method = (string) ($validated['valuation_method'] ?? 'FIFO');
        $result = $report->report(Carbon::now(), $validated['warehouse_id'] ?? null, self::SUMMARY_METHODS[$method]);

        $items = collect($result['items'])->groupBy('stock_item_id')->map(static fn ($rows): array => [
            'item_id' => (string) $rows->first()['stock_item_id'],
            'sku' => (string) $rows->first()['sku'],
            'name' => (string) $rows->first()['name'],
            'valuation' => $rows->reduce(static fn (string $sum, array $row): string => bcadd($sum, $row['value'], 2), '0.00'),
        ])->values();

        return response()->json([
            'valuation_method' => $method,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'total_valuation' => $result['total_value'],
            'items_count' => $items->count(),
            'items' => $items->all(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/valuation/at-date',
        operationId: 'wmsStockValuationAtDate',
        summary: 'Stock valuation report at a date',
        description: 'The stock as it stood at the end of the given day (company time zone), per item and warehouse, valued with the method of the item category: moving_average = perpetual ledger average up to that moment (value in minus value out over net quantity), fifo = latest receipts up to that moment, standard_cost = current standard cost (no history is kept). cost_source says where each unit cost comes from. valuation_method forces one method for every item.',
        tags: ['Inventory Valuation'],
        parameters: [
            new OA\Parameter(name: 'date', in: 'query', required: true, description: 'Y-m-d, today or in the past (a future date is a 422)', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-12-31')),
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
            new OA\Parameter(name: 'valuation_method', in: 'query', required: false, description: 'Lowercase; forces one method for every item. When omitted each item is valued with the method of its category', schema: new OA\Schema(type: 'string', enum: ['fifo', 'moving_average', 'standard_cost'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valuation at the date: the report object itself, NOT wrapped in data (no success/message envelope)', content: new OA\JsonContent(ref: '#/components/schemas/WmsValuationReport')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function atDate(WmsQueryRequest $request, StockValuationReportService $report, TenantClock $clock): JsonResponse
    {
        $endOfDay = CarbonImmutable::parse((string) $request->input('date'), $clock->timezone())->endOfDay()->utc();

        return response()->json($report->report($endOfDay, $request->input('warehouse_id'), $request->input('valuation_method')));
    }

    #[OA\Get(
        path: '/api/v1/inventory/kpis',
        operationId: 'wmsInventoryKpis',
        summary: 'Warehouse KPIs',
        description: 'Over the period from..to (company days): inventory turnover (consumption cost / average of the stock value at start and end), coverage days (end value / average daily consumption), dead stock (items with stock and no consumption in the last dead_stock_days days, with value) and inventory record accuracy (counted lines that matched the system / counted lines, plus the absolute value of the differences). A ratio without a base (no stock, no consumption, nothing counted) is null.',
        tags: ['Inventory Valuation'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'dead_stock_days', in: 'query', required: true, description: 'Days without consumption after which stock is dead', schema: new OA\Schema(type: 'integer', example: 180)),
            new OA\Parameter(ref: '#/components/parameters/WmsWarehouseFilter'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'KPIs', content: new OA\JsonContent(ref: '#/components/schemas/WmsKpis')),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    public function kpis(WmsQueryRequest $request, InventoryKpiService $kpis, TenantClock $clock): JsonResponse
    {
        return response()->json($kpis->kpis(
            CarbonImmutable::parse((string) $request->input('from'), $clock->timezone()),
            CarbonImmutable::parse((string) $request->input('to'), $clock->timezone()),
            $request->input('warehouse_id'),
            (int) $request->input('dead_stock_days'),
        ));
    }

    #[OA\Post(
        path: '/api/v1/inventory/valuation/snapshot',
        summary: 'Capture today\'s valuation snapshot of an item',
        description: 'Stores (or refreshes) today\'s valuation of the item in the warehouse, valued with the method of its category, and announces it (InventoryValuationCalculatedEvent). The daily job does the same for every item with stock.',
        tags: ['Inventory Valuation'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock_item_id', 'warehouse_id'],
                properties: [
                    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Snapshot captured'),
            new OA\Response(ref: '#/components/responses/WmsUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/WmsForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/WmsUnprocessable', response: 422),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Capture periodic valuation snapshot', tags: ['Inventory'])]
    public function captureSnapshot(Request $request, StockValuationServiceInterface $service): JsonResponse
    {
        $validated = $request->validate([
            'stock_item_id' => ['required', 'uuid', $this->tenantExists('stock_items')],
            'warehouse_id' => ['required', 'uuid', $this->tenantExists('warehouses')],
        ]);

        $snapshot = $service->generateDailySnapshot(StockItem::query()->findOrFail($validated['stock_item_id']), (string) $validated['warehouse_id']);

        return response()->json($snapshot, 201);
    }
}
