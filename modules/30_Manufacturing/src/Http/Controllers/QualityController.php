<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Manufacturing\Contracts\QualityManagementServiceInterface;
use Modules\Manufacturing\DTOs\RecordQualityInspectionDTO;
use Modules\Manufacturing\Http\Controllers\Concerns\InteractsWithManufacturing;
use Modules\Manufacturing\Http\Requests\RecordQualityInspectionRequest;
use Modules\Manufacturing\Http\Requests\ResolveNonConformanceRequest;
use Modules\Manufacturing\Http\Requests\StoreNonConformanceRequest;
use Modules\Manufacturing\Http\Requests\StoreQualityPlanRequest;
use Modules\Manufacturing\Http\Resources\MfgNonConformanceResource;
use Modules\Manufacturing\Http\Resources\MfgQualityInspectionResource;
use Modules\Manufacturing\Http\Resources\MfgQualityPlanResource;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use Modules\Manufacturing\Models\MfgQualityPlan;
use OpenApi\Attributes as OA;

/**
 * Quality management: inspection plans, inspections at receipt, operation and final points, non-conformances.
 */
final class QualityController extends Controller
{
    use InteractsWithManufacturing;

    public function __construct(private readonly QualityManagementServiceInterface $quality) {}

    /**
     * Paginated inspection plans.
     */
    #[OA\Get(path: '/api/v1/manufacturing/quality/plans', operationId: 'listMfgQualityPlans', description: 'Paginated inspection plans of the active company with their characteristics.', summary: 'List inspection plans', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Inspection plans', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgQualityPlan'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function plans(Request $request): AnonymousResourceCollection
    {
        return MfgQualityPlanResource::collection(MfgQualityPlan::query()->with('characteristics')->where('tenant_id', $this->tenantId())->orderBy('plan_code')->paginate($this->perPage($request)));
    }

    /**
     * Creates an inspection plan.
     */
    #[OA\Post(path: '/api/v1/manufacturing/quality/plans', operationId: 'storeMfgQualityPlan', description: 'Creates an inspection plan with its characteristics, tolerances and the number of defects still accepted.', summary: 'Create inspection plan', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStoreQualityPlanRequest')),
        responses: [new OA\Response(response: 201, description: 'Plan created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgQualityPlan')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function storePlan(StoreQualityPlanRequest $request): JsonResponse
    {
        return (new MfgQualityPlanResource($this->quality->createPlan($this->tenantId(), $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Paginated inspections.
     */
    #[OA\Get(path: '/api/v1/manufacturing/quality/inspections', operationId: 'inspectionsManufacturingQuality', description: 'Paginated inspections of the active company, filtered by production order, inspection point and status.', summary: 'List inspections', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        parameters: [new OA\Parameter(name: 'production_order_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'inspection_point', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['receipt', 'operation', 'final'])), new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Inspections', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgQualityInspection'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function inspections(Request $request): AnonymousResourceCollection
    {
        $query = MfgQualityInspection::query()->where('tenant_id', $this->tenantId());
        foreach (['production_order_id', 'inspection_point', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        return MfgQualityInspectionResource::collection($query->latest('inspected_at')->paginate($this->perPage($request)));
    }

    /**
     * Records an inspection.
     */
    #[OA\Post(path: '/api/v1/manufacturing/quality/inspections', operationId: 'recordInspectionManufacturingQuality', description: 'Records an inspection at receipt, operation or final point: measurements are evaluated against the plan tolerances; a critical characteristic out of tolerance or defects above the plan limit fail it and raise a non-conformance on the rejected quantity.', summary: 'Record inspection', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgRecordInspectionRequest')),
        responses: [new OA\Response(response: 201, description: 'Inspection recorded', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgQualityInspection')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function recordInspection(RecordQualityInspectionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $inspection = $this->quality->executeInspection(new RecordQualityInspectionDTO(
            tenantId: $this->tenantId(),
            qualityPlanId: (string) $data['quality_plan_id'],
            inspectionPoint: (string) $data['inspection_point'],
            productionOrderId: $data['production_order_id'] ?? null,
            productionOrderOperationId: $data['production_order_operation_id'] ?? null,
            materialMovementId: $data['material_movement_id'] ?? null,
            inspectorId: $this->actor($request),
            sampleSize: (int) ($data['sample_size'] ?? 1),
            defectCount: (int) ($data['defect_count'] ?? 0),
            notes: $data['notes'] ?? null,
            characteristicResults: array_values($data['characteristic_results'] ?? []),
            quantityInspected: (string) ($data['quantity_inspected'] ?? '0'),
            quantityRejected: isset($data['quantity_rejected']) ? (string) $data['quantity_rejected'] : null,
        ));

        return (new MfgQualityInspectionResource($inspection))->response()->setStatusCode(201);
    }

    /**
     * Paginated non-conformances.
     */
    #[OA\Get(path: '/api/v1/manufacturing/quality/non-conformances', operationId: 'nonConformancesManufacturingQuality', description: 'Paginated non-conformances of the active company, filtered by status and severity.', summary: 'List non-conformances', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgStatus'), new OA\Parameter(name: 'severity', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['minor', 'major', 'critical'])), new OA\Parameter(ref: '#/components/parameters/MfgPage'), new OA\Parameter(ref: '#/components/parameters/MfgPerPage')],
        responses: [new OA\Response(response: 200, description: 'Non-conformances', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/MfgNonConformance'))])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401)])]
    public function nonConformances(Request $request): AnonymousResourceCollection
    {
        $query = MfgQualityNonConformance::query()->where('tenant_id', $this->tenantId());
        foreach (['status', 'severity'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        return MfgNonConformanceResource::collection($query->latest()->paginate($this->perPage($request)));
    }

    /**
     * Raises a non-conformance by hand.
     */
    #[OA\Post(path: '/api/v1/manufacturing/quality/non-conformances', operationId: 'storeMfgNonConformance', description: 'Raises a non-conformance outside an inspection.', summary: 'Raise non-conformance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgStoreNonConformanceRequest')),
        responses: [new OA\Response(response: 201, description: 'Non-conformance raised', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgNonConformance')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function storeNonConformance(StoreNonConformanceRequest $request): JsonResponse
    {
        return (new MfgNonConformanceResource($this->quality->recordNonConformance($this->tenantId(), $request->validated(), $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * Closes a non-conformance.
     */
    #[OA\Post(path: '/api/v1/manufacturing/quality/non-conformances/{id}/resolve', operationId: 'resolveMfgNonConformance', description: 'Closes an open non-conformance with its disposition and corrective action.', summary: 'Resolve non-conformance', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Manufacturing Quality'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/MfgId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/MfgResolveNonConformanceRequest')),
        responses: [new OA\Response(response: 200, description: 'Non-conformance closed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/MfgNonConformance')])), new OA\Response(ref: '#/components/responses/MfgUnauthenticated', response: 401), new OA\Response(ref: '#/components/responses/MfgForbidden', response: 403), new OA\Response(ref: '#/components/responses/MfgNotFound', response: 404), new OA\Response(ref: '#/components/responses/MfgUnprocessable', response: 422)])]
    public function resolveNonConformance(ResolveNonConformanceRequest $request, string $id): MfgNonConformanceResource
    {
        $data = $request->validated();

        return new MfgNonConformanceResource($this->quality->resolveNonConformance($this->owned(MfgQualityNonConformance::class, $id), (string) $data['disposition'], $data['corrective_action'] ?? null, $this->actor($request)));
    }
}
