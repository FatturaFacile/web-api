<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Models\StockItem;
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
            new OA\Parameter(name: 'valuation_method', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['FIFO', 'AVCO', 'STANDARD'], default: 'FIFO')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Valuation summary'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get total inventory valuation summary', tags: ['Inventory'])]
    public function summary(Request $request, StockValuationServiceInterface $service): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id');
        $method = $request->query('valuation_method', 'FIFO');

        $items = StockItem::where('is_active', true)->get();
        $totalValuation = 0.0;
        $details = [];

        foreach ($items as $item) {
            $onHand = (float) $item->quants()->sum('quantity_on_hand');
            $cost = match ($method) {
                'AVCO' => (float) ($item->current_moving_average_cost * $onHand),
                'STANDARD' => (float) ($item->standard_cost * $onHand),
                default => $service->calculateFifoValuation($item, $onHand),
            };

            $totalValuation += $cost;
            $details[] = [
                'item_id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'valuation' => $cost,
            ];
        }

        return response()->json([
            'valuation_method' => $method,
            'warehouse_id' => $warehouseId,
            'total_valuation' => $totalValuation,
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
                    new OA\Property(property: 'valuation_method', type: 'string', enum: ['FIFO', 'AVCO', 'STANDARD'], default: 'FIFO'),
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
            'valuation_method' => ['nullable', 'string', 'in:FIFO,AVCO,STANDARD'],
        ]);

        $item = StockItem::findOrFail($validated['stock_item_id']);
        $snapshot = $service->capturePeriodicSnapshot(
            item: $item,
            warehouseId: (string) $validated['warehouse_id'],
            method: $validated['valuation_method'] ?? 'FIFO'
        );

        return response()->json($snapshot, 201);
    }
}
