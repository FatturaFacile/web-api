<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\ResolvesTenant;
use Modules\Manufacturing\Http\Requests\RecordQualityInspectionRequest;
use Modules\Manufacturing\Http\Resources\MfgQualityInspectionResource;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use OpenApi\Attributes as OA;

final class QualityController extends Controller
{
    use ResolvesTenant;

    public function __construct(
        private QualityManagementServiceInterface $qmService
    ) {}

    /**
     * Returns the paginated quality inspections of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/quality/inspections',
        operationId: 'getInspectionsManufacturingQuality',
        description: 'Returns the paginated quality inspections of the active tenant, latest first.',
        summary: 'List quality inspections',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Quality'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgQualityInspectionResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function inspections(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $inspections = MfgQualityInspection::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json([
            'data' => MfgQualityInspectionResource::collection($inspections),
            'meta' => [
                'current_page' => $inspections->currentPage(),
                'total' => $inspections->total(),
            ],
        ]);
    }

    /**
     * Records a quality inspection against a quality plan.
     */
    #[OA\Post(
        path: '/api/v1/manufacturing/quality/inspections',
        operationId: 'recordInspectionManufacturingQualityInspections',
        description: 'Records a quality inspection against a quality plan.',
        summary: 'Record quality inspection',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Quality'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ManufacturingRecordQualityInspectionRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Inspection created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ManufacturingMfgQualityInspectionResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function recordInspection(RecordQualityInspectionRequest $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $validated = $request->validated();

        $dto = new RecordQualityInspectionDTO(
            tenantId: $tenantId,
            qualityPlanId: $validated['quality_plan_id'],
            productionOrderId: $validated['production_order_id'] ?? null,
            operationId: $validated['operation_id'] ?? null,
            inspectorId: $validated['inspector_id'] ?? null,
            sampleSize: (int) ($validated['sample_size'] ?? 1),
            defectCount: (int) ($validated['defect_count'] ?? 0),
            notes: $validated['notes'] ?? null,
            characteristicResults: $validated['characteristic_results'] ?? []
        );

        $inspection = $this->qmService->executeInspection($dto);

        return response()->json([
            'message' => 'Quality inspection recorded.',
            'data' => new MfgQualityInspectionResource($inspection),
        ], 201);
    }

    /**
     * Returns the paginated quality non-conformances of the active tenant, latest first.
     */
    #[OA\Get(
        path: '/api/v1/manufacturing/quality/non-conformances',
        operationId: 'getNonConformancesManufacturingQuality',
        description: 'Returns the paginated quality non-conformances of the active tenant, latest first.',
        summary: 'List non-conformances',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Manufacturing Quality'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ManufacturingMfgQualityNonConformanceModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function nonConformances(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $ncs = MfgQualityNonConformance::where('tenant_id', $tenantId)->latest()->paginate(25);

        return response()->json(['data' => $ncs]);
    }
}
