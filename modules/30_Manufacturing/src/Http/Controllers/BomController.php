<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\CreateBomRequest;
use Modules\Manufacturing\Http\Requests\ReviseBomRequest;
use Modules\Manufacturing\Http\Resources\MfgBomResource;
use Modules\Manufacturing\Models\MfgBom;
use OpenApi\Attributes as OA;

final class BomController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private BillOfMaterialsServiceInterface $bomService
    ) {}

    /**
     * Returns the paginated list of boms of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/boms',
        operationId: 'listManufacturingBoms',
        description: 'Returns the paginated list of boms of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List boms',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing BOMs'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of boms', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgBomResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $boms = MfgBom::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgBomResource::collection($boms),
            'meta' => [
                'current_page' => $boms->currentPage(),
                'total' => $boms->total(),
            ],
        ]);
    }

    /**
     * Creates a new bom in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/boms',
        operationId: 'createManufacturingBom',
        description: 'Creates a new bom in the active tenant.',
        summary: 'Create bom',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing BOMs'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManufacturingCreateBomRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Bom created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgBomResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(CreateBomRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new CreateBomDTO(
            tenantId: $tenantId,
            mfgItemId: $validated['mfg_item_id'],
            name: $validated['name'],
            revision: $validated['revision'] ?? 'A',
            bomType: $validated['bom_type'] ?? 'standard',
            baseQuantity: (float) ($validated['base_quantity'] ?? 1.0),
            uom: $validated['uom'] ?? 'PCS',
            effectiveFrom: $validated['effective_from'] ?? null,
            effectiveTo: $validated['effective_to'] ?? null,
            components: $validated['components']
        );

        $bom = $this->bomService->createBom($dto);

        return response()->json([
            'message' => 'Bill of Materials created successfully.',
            'data' => new MfgBomResource($bom),
        ], 201);
    }

    /**
     * Returns a single bom of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/boms/{id}',
        operationId: 'getManufacturingBom',
        description: 'Returns a single bom of the active tenant.',
        summary: 'Get bom',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing BOMs'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the bom', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgBomResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $bom = MfgBom::where('tenant_id', $tenantId)->findOrFail($id);

        return response()->json(['data' => new MfgBomResource($bom)]);
    }

    /**
     * Returns the component tree of a bill of materials for the given quantity.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/boms/{id}/explode',
        operationId: 'getExplodeManufacturingBom',
        description: 'Returns the component tree of a bill of materials for the given quantity.',
        summary: 'Explode bill of materials',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing BOMs'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the bom', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'quantity', in: 'query', required: false, description: 'Filter by quantity', schema: new OA\Schema(type: 'number', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'bom_id'), new OA\Property(property: 'quantity', type: 'number'), new OA\Property(property: 'exploded_components', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'component_item_id', type: 'string'), new OA\Property(property: 'component_item_code', type: 'string'), new OA\Property(property: 'component_name', type: 'string'), new OA\Property(property: 'level', type: 'integer'), new OA\Property(property: 'quantity_per', type: 'number'), new OA\Property(property: 'scrap_percentage', type: 'number'), new OA\Property(property: 'effective_quantity', type: 'number'), new OA\Property(property: 'uom', type: 'string'), new OA\Property(property: 'is_phantom', type: 'boolean'), new OA\Property(property: 'is_subassembly', type: 'boolean')]))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function explode(Request $request, string $id): JsonResponse
    {
        $units = (float) $request->query('quantity', 1.0);
        $tree = $this->bomService->explodeBom($id, $units);

        return response()->json([
            'bom_id' => $id,
            'quantity' => $units,
            'exploded_components' => $tree,
        ]);
    }

    /**
     * Creates a new revision of a bill of materials.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/boms/{id}/revise',
        operationId: 'reviseManufacturingBom',
        description: 'Creates a new revision of a bill of materials.',
        summary: 'Revise bill of materials',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing BOMs'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the bom', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManufacturingReviseBomRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgBomResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function revise(ReviseBomRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $bom = $this->bomService->reviseBom($id, $validated['new_revision'], $validated['reason']);

        return response()->json([
            'message' => 'BOM revised successfully.',
            'data' => new MfgBomResource($bom),
        ]);
    }
}
