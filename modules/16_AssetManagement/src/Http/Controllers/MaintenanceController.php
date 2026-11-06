<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Http\Requests\CompleteMaintenanceWorkOrderRequest;
use Modules\AssetManagement\Http\Requests\StoreMaintenanceWorkOrderRequest;
use Modules\AssetManagement\Http\Resources\MaintenanceWorkOrderResource;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use OpenApi\Attributes as OA;

final class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceManagementServiceInterface $maintenanceService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the first page of the maintenance plans of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/plans',
        operationId: 'getIndexPlansEamMaintenancePlans',
        description: 'Returns the first page of the maintenance plans of the active tenant.',
        summary: 'List maintenance plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementMaintenancePlanModel')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function indexPlans(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $plans = MaintenancePlan::where('tenant_id', $tenant->id)->paginate(25);

        return response()->json(['data' => $plans->items()]);
    }

    /**
     * Creates a maintenance plan.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/plans',
        operationId: 'storePlanEamMaintenancePlans',
        description: 'Creates a maintenance plan. The whole request body is passed to the maintenance service.',
        summary: 'Create maintenance plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        requestBody: new OA\RequestBody(required: true, description: 'Free-form attributes: the controller forwards the whole body to the domain service.', content: new OA\JsonContent(type: 'object', additionalProperties: true)),
        responses: [
            new OA\Response(response: 201, description: 'Plan created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenancePlanModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function storePlan(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $data = $request->all();
        $data['tenant_id'] = (string) $tenant->id;

        $plan = $this->maintenanceService->createMaintenancePlan($data, $actorId);

        return response()->json(['data' => $plan], 201);
    }

    /**
     * Returns the paginated maintenance work orders of the active tenant, latest scheduled first.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/work-orders',
        operationId: 'getIndexWorkOrdersEamMaintenanceWorkOrders',
        description: 'Returns the paginated maintenance work orders of the active tenant, latest scheduled first.',
        summary: 'List maintenance work orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function indexWorkOrders(): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $workOrders = MaintenanceWorkOrder::where('tenant_id', $tenant->id)
            ->orderByDesc('scheduled_date')
            ->paginate(25);

        return response()->json([
            'data' => MaintenanceWorkOrderResource::collection($workOrders),
            'meta' => [
                'current_page' => $workOrders->currentPage(),
                'total' => $workOrders->total(),
            ],
        ]);
    }

    /**
     * Creates a maintenance work order for an asset.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders',
        operationId: 'storeWorkOrderEamMaintenanceWorkOrders',
        description: 'Creates a maintenance work order for an asset.',
        summary: 'Create maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreMaintenanceWorkOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Work order created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeWorkOrder(StoreMaintenanceWorkOrderRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $actorId = (string) ($request->user()?->id ?? '');

        $wo = $this->maintenanceService->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
            tenantId: (string) $tenant->id,
            assetId: (string) $request->input('asset_id'),
            maintenanceType: (string) $request->input('maintenance_type', 'preventive'),
            priority: (string) $request->input('priority', 'medium'),
            scheduledDate: $request->input('scheduled_date'),
            planId: $request->input('plan_id'),
            assignedTechnicianId: $request->input('assigned_technician_id'),
            problemDescription: $request->input('problem_description'),
            estimatedCost: (float) $request->input('estimated_cost', 0.0)
        ), $actorId);

        return response()->json(['data' => new MaintenanceWorkOrderResource($wo)], 201);
    }

    /**
     * Returns a maintenance work order of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/work-orders/{id}',
        operationId: 'getShowWorkOrderEamMaintenanceWorkOrders',
        description: 'Returns a maintenance work order of the active tenant.',
        summary: 'Get maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function showWorkOrder(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $wo = MaintenanceWorkOrder::where('tenant_id', $tenant->id)->findOrFail($id);

        return response()->json(['data' => new MaintenanceWorkOrderResource($wo)]);
    }

    /**
     * Completes a maintenance work order, recording downtime and costs.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders/{id}/complete',
        operationId: 'completeWorkOrderEamMaintenanceWorkOrder',
        description: 'Completes a maintenance work order, recording downtime and costs.',
        summary: 'Complete maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementCompleteMaintenanceWorkOrderRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function completeWorkOrder(CompleteMaintenanceWorkOrderRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $wo = MaintenanceWorkOrder::where('tenant_id', $tenant->id)->findOrFail($id);
        $actorId = (string) ($request->user()?->id ?? '');

        $completed = $this->maintenanceService->completeWorkOrder(new CompleteMaintenanceWorkOrderDTO(
            workOrderId: (string) $wo->id,
            downtimeHours: (float) $request->input('downtime_hours', 0.0),
            laborCost: (float) $request->input('labor_cost', 0.0),
            partsCost: (float) $request->input('parts_cost', 0.0),
            externalServiceCost: (float) $request->input('external_service_cost', 0.0),
            capitalizeAsUpgrade: (bool) $request->input('capitalize_as_upgrade', false),
            conditionAfterMaintenance: (string) $request->input('condition_after_maintenance', 'good'),
            resolutionNotes: $request->input('resolution_notes'),
            partsConsumed: $request->input('parts_consumed', [])
        ), $actorId);

        return response()->json(['data' => new MaintenanceWorkOrderResource($completed)]);
    }
}
