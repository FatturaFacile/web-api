<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Inventory\Actions\GenerateInventoryBookAction;
use Modules\Inventory\Http\Requests\InventoryBookRequest;
use Modules\Inventory\Http\Resources\InventoryBookExportResource;
use Modules\Inventory\Models\InventoryBookExport;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Book', description: 'Year-end inventory book (libro inventari) with GL reconciliation')]
final class InventoryBookController extends Controller
{
    #[OA\Get(
        path: '/api/v1/inventory/book',
        summary: 'Get inventory book',
        description: 'Quantities per item / lot / warehouse as of a date, valued per the effective method, reconciled with the GL inventory balance. format=csv returns a `;`-separated UTF-8 attachment.',
        tags: ['Inventory Book'],
        parameters: [
            new OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', example: 2026)),
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'warehouse_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'format', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['json', 'csv'], default: 'json')),
            new OA\Parameter(name: 'require_closed', in: 'query', required: false, schema: new OA\Schema(type: 'boolean', default: false)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Inventory book', content: new OA\JsonContent(ref: '#/components/schemas/InventoryBook')),
            new OA\Response(response: 403, description: 'Missing inventory.book.export (CSV)'),
            new OA\Response(response: 409, description: 'as_of falls in an open period and require_closed=true'),
            new OA\Response(response: 422, description: 'Fiscal year not found or invalid parameters'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Get inventory book', tags: ['Inventory'])]
    public function show(InventoryBookRequest $request, GenerateInventoryBookAction $action): JsonResponse|Response
    {
        $this->authorize($request->wantsCsv() ? 'export' : 'viewAny', InventoryBookExport::class);

        $book = $action->build($request->year(), $request->asOf(), $request->warehouseId(), $request->requireClosed());

        if ($request->wantsCsv()) {
            return $this->csvResponse($action->csvFromBook($book), $request->year());
        }

        return response()->json($book);
    }

    #[OA\Get(
        path: '/api/v1/inventory/book/exports',
        summary: 'List inventory book exports',
        tags: ['Inventory Book'],
        parameters: [new OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated exports', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryBookExport')),
            ])),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List inventory book exports', tags: ['Inventory'])]
    public function indexExports(Request $request): JsonResponse
    {
        $this->authorize('viewAny', InventoryBookExport::class);

        $request->validate([
            'year' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $query = InventoryBookExport::query()->orderByDesc('generated_at');
        if ($request->filled('year')) {
            $query->where('year', (int) $request->query('year'));
        }

        $exports = $query->paginate((int) $request->query('per_page', 25));

        return response()->json(InventoryBookExportResource::collection($exports)->response()->getData(true));
    }

    #[OA\Post(
        path: '/api/v1/inventory/book/exports',
        summary: 'Persist an inventory book export',
        description: 'Generates and stores an immutable, SHA-256 checksummed export for audit retrieval.',
        tags: ['Inventory Book'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['year'],
            properties: [
                new OA\Property(property: 'year', type: 'integer', example: 2026),
                new OA\Property(property: 'as_of', type: 'string', format: 'date'),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'require_closed', type: 'boolean'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Export stored', content: new OA\JsonContent(ref: '#/components/schemas/InventoryBookExport')),
            new OA\Response(response: 403, description: 'Missing inventory.book.export'),
            new OA\Response(response: 409, description: 'Period not closed (require_closed)'),
            new OA\Response(response: 422, description: 'Fiscal year not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Persist an inventory book export', tags: ['Inventory'])]
    public function storeExport(InventoryBookRequest $request, GenerateInventoryBookAction $action): JsonResponse
    {
        $this->authorize('export', InventoryBookExport::class);

        $actorId = auth()->id();
        $export = $action->export($request->year(), $request->asOf(), $request->warehouseId(), $request->requireClosed(), $actorId !== null ? (string) $actorId : null);

        return response()->json(new InventoryBookExportResource($export), 201);
    }

    #[OA\Get(
        path: '/api/v1/inventory/book/exports/{id}/download',
        summary: 'Download a stored inventory book export (CSV)',
        tags: ['Inventory Book'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'CSV attachment', content: new OA\MediaType(mediaType: 'text/csv')),
            new OA\Response(response: 403, description: 'Missing inventory.book.export'),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Download a stored inventory book export', tags: ['Inventory'])]
    public function downloadExport(string $id, GenerateInventoryBookAction $action): Response
    {
        $export = InventoryBookExport::query()->findOrFail($id);
        $this->authorize('download', $export);

        $response = $this->csvResponse($action->csv($export), $export->year);
        $response->headers->set('X-Content-Checksum', $export->checksum);

        return $response;
    }

    private function csvResponse(string $csv, int $year): Response
    {
        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="inventory-book-'.$year.'.csv"',
        ]);
    }
}
