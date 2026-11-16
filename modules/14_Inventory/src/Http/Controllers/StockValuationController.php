<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Enums\ValuationMethod;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Support\Decimal;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Valuation', description: 'FIFO, Moving Average, Standard Costing, and balance sheet valuations')]
final class StockValuationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/valuation/summary',
        summary: 'Get total inventory valuation summary',
        description: 'Calculates asset value across warehouses using standard, moving average, or FIFO costing.',
        tags: ['Inventory Valuation'],
        parameters: [
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'valuation_method', in: 'query', required: false, description: 'Force a method for all items; omitted = effective method per item. Legacy FIFO|AVCO|STANDARD accepted.', schema: new OA\Schema(type: 'string', enum: ['fifo', 'weighted_average', 'standard_cost', 'FIFO', 'AVCO', 'STANDARD'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valuation summary'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get total inventory valuation summary', tags: ['Inventory'])]
    public function summary(Request $request, StockValuationServiceInterface $service, InventoryCostingServiceInterface $costing): JsonResponse
    {
        $request->validate([
            'warehouse_id' => ['sometimes', 'nullable', 'uuid'],
            'valuation_method' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', ValuationMethod::acceptedInputValues())],
        ]);

        $warehouseId = $request->query('warehouse_id');
        $requested = $request->filled('valuation_method') ? (string) $request->query('valuation_method') : null;
        $requestedMethod = $requested !== null ? ValuationMethod::fromStorage($requested) : null;

        $items = StockItem::with('category')->where('is_active', true)->get();
        $totalValuation = '0';
        $details = [];

        foreach ($items as $item) {
            $method = $requestedMethod ?? $costing->resolveEffectiveMethod($item);
            $value = $service->valueItem($item, $method->value);

            $totalValuation = Decimal::add($totalValuation, $value);
            $details[] = [
                'item_id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'method' => $method->value,
                'valuation' => (float) $value,
            ];
        }

        return response()->json([
            'valuation_method' => $requested ?? 'effective',
            'warehouse_id' => $warehouseId,
            'total_valuation' => (float) Decimal::money($totalValuation),
            'items_count' => count($details),
            'items' => $details,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/valuation/snapshot',
        summary: 'Capture periodic valuation snapshot',
        description: 'Creates an immutable valuation snapshot for General Ledger balance sheet reconciliation.',
        tags: ['Inventory Valuation'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['stock_item_id', 'warehouse_id'],
                properties: [
                    new OA\Property(property: 'stock_item_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'valuation_method', type: 'string', enum: ['fifo', 'weighted_average', 'standard_cost', 'FIFO', 'AVCO', 'STANDARD'], description: 'Omitted = effective method of the item'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Snapshot captured'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Capture periodic valuation snapshot', tags: ['Inventory'])]
    public function captureSnapshot(Request $request, StockValuationServiceInterface $service): JsonResponse
    {
        $validated = $request->validate([
            'stock_item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'valuation_method' => ['nullable', 'string', 'in:'.implode(',', ValuationMethod::acceptedInputValues())],
        ]);

        $item = StockItem::findOrFail($validated['stock_item_id']);
        $snapshot = $service->capturePeriodicSnapshot(
            item: $item,
            warehouseId: (string) $validated['warehouse_id'],
            method: $validated['valuation_method'] ?? null
        );

        return response()->json($snapshot, 201);
    }
}
