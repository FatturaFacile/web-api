<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Http\Resources\StockQuantResource;
use Modules\Inventory\Models\StockQuant;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Stock Quants', description: 'Real-time quant balances by warehouse, bin, lot, and serial number')]
final class StockQuantController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/quants',
        summary: 'List stock quants',
        description: 'Query real-time stock balances across warehouses, bins, lots, and serials.',
        tags: ['Inventory Stock Quants'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'warehouse_bin_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'stock_item_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lot_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'available_only', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated list of stock quants'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List stock quants', tags: ['Inventory'])]
    public function index(Request $request): JsonResponse
    {
        $query = StockQuant::with(['warehouse', 'bin', 'item', 'lot', 'serialNumber'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->query('warehouse_id'));
        }

        if ($request->filled('warehouse_bin_id')) {
            $query->where('warehouse_bin_id', $request->query('warehouse_bin_id'));
        }

        if ($request->filled('stock_item_id')) {
            $query->where('stock_item_id', $request->query('stock_item_id'));
        }

        if ($request->filled('lot_id')) {
            $query->where('lot_id', $request->query('lot_id'));
        }

        if ($request->boolean('available_only')) {
            $query->where('available_quantity', '>', 0);
        }

        $quants = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(StockQuantResource::collection($quants)->response()->getData(true));
    }

    #[OA\Get(
        path: '/api/v1/inventory/quants/{id}',
        summary: 'Get stock quant details',
        description: 'Retrieve quant location, lot/serial links, and exact reservation status.',
        tags: ['Inventory Stock Quants'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock quant details'),
            new OA\Response(response: 404, description: 'Stock quant not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get stock quant details', tags: ['Inventory'])]
    public function show(string $id): JsonResponse
    {
        $quant = StockQuant::with(['warehouse', 'bin', 'item', 'lot', 'serialNumber'])->findOrFail($id);

        return response()->json(new StockQuantResource($quant));
    }
}
