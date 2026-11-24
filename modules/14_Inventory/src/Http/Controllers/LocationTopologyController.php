<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Exceptions\StockRuleViolation;
use Modules\Inventory\Http\Requests\BulkGenerateLocationsRequest;
use Modules\Inventory\Http\Requests\LocationLabelsRequest;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\LocationTopologyService;
use OpenApi\Attributes as OA;

/**
 * The location hierarchy of a warehouse for the topology explorer: tree, capacity summary, bulk generation, labels and
 * the stock in transit between warehouses. Changes are a manager decision (`inventory.manage`).
 */
#[OA\Tag(name: 'Inventory Warehouses', description: 'Warehouse master data and locations')]
final class LocationTopologyController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/warehouses/{id}/tree',
        operationId: 'wmsWarehouseTree',
        summary: 'Warehouse location tree',
        description: 'Zone > aisle > rack > bin hierarchy of the warehouse with the occupancy and utilisation percentages of every bin.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The tree', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function tree(string $id, LocationTopologyService $topology): JsonResponse
    {
        return response()->json(['data' => $topology->tree(Warehouse::query()->findOrFail($id))]);
    }

    #[OA\Get(
        path: '/api/v1/inventory/warehouses/{id}/capacity-summary',
        operationId: 'wmsWarehouseCapacitySummary',
        summary: 'Capacity summary per zone',
        description: 'Bins, blocked bins, weight and volume held against the declared limits, per zone.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Zone capacity', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(response: 404, description: 'Warehouse not found'),
        ]
    )]
    public function capacitySummary(string $id, LocationTopologyService $topology): JsonResponse
    {
        return response()->json(['data' => $topology->capacitySummary(Warehouse::query()->findOrFail($id))]);
    }

    #[OA\Post(
        path: '/api/v1/inventory/warehouses/{id}/locations/bulk',
        operationId: 'wmsBulkGenerateLocations',
        summary: 'Generate a zone layout',
        description: 'Creates aisles A01.., racks R01.., shelves S01.. and bins B01.. of one zone in a single transaction, with coordinates and the given limits. Manager decision; at most 5000 bins per call; refused when a barcode already exists.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['zone_code', 'aisles', 'racks', 'shelves', 'bins'], properties: [
            new OA\Property(property: 'zone_code', type: 'string', example: 'Z-PICK'),
            new OA\Property(property: 'zone_type', type: 'string', example: 'storage'),
            new OA\Property(property: 'aisles', type: 'integer', example: 10),
            new OA\Property(property: 'racks', type: 'integer', example: 20),
            new OA\Property(property: 'shelves', type: 'integer', example: 5),
            new OA\Property(property: 'bins', type: 'integer', example: 4),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'Layout created'),
            new OA\Response(response: 403, description: 'Not a manager'),
            new OA\Response(response: 422, description: 'Validation failed, layout too large or barcode collision'),
        ]
    )]
    public function bulk(string $id, BulkGenerateLocationsRequest $request, LocationTopologyService $topology): JsonResponse
    {
        $this->authorize('inventory.manage');
        $warehouse = Warehouse::query()->findOrFail($id);

        try {
            $bins = $topology->bulkGenerate($warehouse, $request->validated());
        } catch (StockRuleViolation $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['aisles' => [$e->getMessage()]]], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['created' => count($bins), 'data' => array_map(static fn (WarehouseBin $bin): array => ['id' => (string) $bin->id, 'full_path_code' => $bin->full_path_code, 'barcode' => $bin->barcode], array_slice($bins, 0, 50))], Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/inventory/labels/locations',
        operationId: 'wmsLocationLabels',
        summary: 'Print location labels',
        description: 'Labels for bins as a PDF sheet or raw ZPL, in 100x50 mm or 50x25 mm. Each label shows the location path, a Code128 and a QR code of the bin barcode.',
        tags: ['Inventory Warehouses'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['bin_ids', 'format'], properties: [
            new OA\Property(property: 'bin_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
            new OA\Property(property: 'format', type: 'string', enum: ['pdf', 'zpl']),
            new OA\Property(property: 'size', type: 'string', enum: ['100x50', '50x25']),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The labels (application/pdf or text/plain ZPL)'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function labels(LocationLabelsRequest $request, LocationTopologyService $topology): Response
    {
        $bins = WarehouseBin::query()->with('rack.aisle.zone')->whereIn('id', $request->input('bin_ids'))->orderBy('full_path_code')->get();
        [$width, $height] = array_map('intval', explode('x', (string) $request->input('size', '100x50')));

        if ($request->input('format') === 'zpl') {
            return response($topology->zpl($bins, $width, $height), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response(Pdf::loadHTML($topology->sheetHtml($bins, $width, $height))->setPaper('a4')->output(), 200, ['Content-Type' => 'application/pdf']);
    }

    #[OA\Get(
        path: '/api/v1/inventory/in-transit',
        operationId: 'wmsInTransitStock',
        summary: 'Stock in transit between warehouses',
        description: 'Transfer lines shipped and not yet fully received, with shipped, received, damaged and outstanding quantities; optionally of one destination warehouse.',
        tags: ['Inventory Warehouses'],
        parameters: [new OA\Parameter(name: 'destination_warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'In-transit lines', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))]))]
    )]
    public function inTransit(Request $request, LocationTopologyService $topology): JsonResponse
    {
        $destination = $request->filled('destination_warehouse_id') ? (string) $request->query('destination_warehouse_id') : null;

        return response()->json(['data' => $topology->inTransit($destination)]);
    }
}
