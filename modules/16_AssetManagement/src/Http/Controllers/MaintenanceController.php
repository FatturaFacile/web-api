<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\AssetManagement\Contracts\MaintenanceManagementServiceInterface;
use Modules\AssetManagement\DTOs\CompleteMaintenanceWorkOrderDTO;
use Modules\AssetManagement\DTOs\CreateMaintenanceWorkOrderDTO;
use Modules\AssetManagement\Http\Requests\CancelWorkOrderRequest;
use Modules\AssetManagement\Http\Requests\CompleteMaintenanceWorkOrderRequest;
use Modules\AssetManagement\Http\Requests\ConsumePartRequest;
use Modules\AssetManagement\Http\Requests\GenerateWorkOrderRequest;
use Modules\AssetManagement\Http\Requests\StartWorkOrderRequest;
use Modules\AssetManagement\Http\Requests\StoreMaintenancePlanRequest;
use Modules\AssetManagement\Http\Requests\StoreMaintenanceWorkOrderRequest;
use Modules\AssetManagement\Http\Resources\MaintenancePlanResource;
use Modules\AssetManagement\Http\Resources\MaintenanceWorkOrderResource;
use Modules\AssetManagement\Http\Resources\WorkOrderPartResource;
use Modules\AssetManagement\Models\MaintenancePlan;
use Modules\AssetManagement\Models\MaintenanceWorkOrder;
use Modules\AssetManagement\Models\WorkOrderPart;
use OpenApi\Attributes as OA;

/**
 * Maintenance (EAM): preventive plans by time or meter, due plans, work orders with start, spare parts, completion
 * and cancellation.
 */
final class MaintenanceController extends Controller
{
    public function __construct(
        private readonly MaintenanceManagementServiceInterface $maintenanceService,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Paginated maintenance plans.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/plans',
        operationId: 'getIndexPlansEamMaintenancePlans',
        description: 'Paginated maintenance plans of the active company, optionally of one asset or only the active ones.',
        summary: 'List maintenance plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(name: 'asset_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'active', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')), new OA\Parameter(ref: '#/components/parameters/EamPage'), new OA\Parameter(ref: '#/components/parameters/EamPerPage')],
        responses: [
            new OA\Response(response: 200, description: 'Plans', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementMaintenancePlanResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function indexPlans(Request $request): AnonymousResourceCollection
    {
        $query = MaintenancePlan::where('tenant_id', $this->tenantContext->getTenantId());

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (string) $request->query('asset_id'));
        }
        if ($request->boolean('active')) {
            $query->where('is_active', true);
        }

        return MaintenancePlanResource::collection($query->orderBy('next_due_date')->paginate($this->perPage($request)));
    }

    /**
     * Creates a maintenance plan.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/plans',
        operationId: 'storePlanEamMaintenancePlans',
        description: 'Creates a preventive plan for an asset (or an asset class) triggered by time (frequency_days), meter (meter_code + meter_interval_units) or whichever comes first.',
        summary: 'Create maintenance plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreMaintenancePlanRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Plan created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenancePlanResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function storePlan(StoreMaintenancePlanRequest $request): JsonResponse
    {
        $plan = $this->maintenanceService->createMaintenancePlan([...$request->validated(), 'tenant_id' => (string) $this->tenantContext->getTenantId()], $this->actor($request));

        return (new MaintenancePlanResource($plan))->response()->setStatusCode(201);
    }

    /**
     * Plans due today (or by a date).
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/plans/due',
        operationId: 'dueEamMaintenancePlans',
        description: 'Active asset plans due by `as_of` (default today): time plans whose next due date minus the lead time has come, meter plans whose latest reading reached the next due meter.',
        summary: 'List due maintenance plans',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Due plans', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementMaintenancePlanResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function duePlans(Request $request): AnonymousResourceCollection
    {
        $asOf = $request->validate(['as_of' => ['nullable', 'date']])['as_of'] ?? null;

        return MaintenancePlanResource::collection($this->maintenanceService->duePlans((string) $this->tenantContext->getTenantId(), $asOf));
    }

    /**
     * One maintenance plan.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/plans/{id}',
        operationId: 'showEamMaintenancePlan',
        description: 'Returns a maintenance plan of the active company.',
        summary: 'Get maintenance plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamPlanId')],
        responses: [
            new OA\Response(response: 200, description: 'Plan', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenancePlanResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function showPlan(string $id): MaintenancePlanResource
    {
        return new MaintenancePlanResource($this->plan($id));
    }

    /**
     * Updates a maintenance plan.
     */
    #[OA\Put(
        path: '/api/v1/eam/maintenance/plans/{id}',
        operationId: 'updateEamMaintenancePlan',
        description: 'Updates a maintenance plan (frequency, meter interval, lead time, activation).',
        summary: 'Update maintenance plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamPlanId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreMaintenancePlanRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Plan updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenancePlanResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function updatePlan(StoreMaintenancePlanRequest $request, string $id): MaintenancePlanResource
    {
        return new MaintenancePlanResource($this->maintenanceService->updateMaintenancePlan($this->plan($id), $request->validated(), $this->actor($request)));
    }

    /**
     * Opens the work order of a plan.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/plans/{id}/work-orders',
        operationId: 'generateEamMaintenancePlanWorkOrder',
        description: 'Opens the preventive work order of an active asset plan; a plan has at most one open work order.',
        summary: 'Generate work order from plan',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamPlanId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementGenerateWorkOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Work order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function generateWorkOrder(GenerateWorkOrderRequest $request, string $id): JsonResponse
    {
        $workOrder = $this->maintenanceService->generateWorkOrderFromPlan($this->plan($id), $request->validated('scheduled_date'), $this->actor($request));

        return (new MaintenanceWorkOrderResource($workOrder))->response()->setStatusCode(201);
    }

    /**
     * Paginated work orders.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/work-orders',
        operationId: 'getIndexWorkOrdersEamMaintenanceWorkOrders',
        description: 'Paginated work orders of the active company, latest scheduled first. Filters: status, asset_id, maintenance_type.',
        summary: 'List maintenance work orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'asset_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'maintenance_type', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(ref: '#/components/parameters/EamPage'),
            new OA\Parameter(ref: '#/components/parameters/EamPerPage'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Work orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource'))])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
        ]
    )]
    public function indexWorkOrders(Request $request): AnonymousResourceCollection
    {
        $query = MaintenanceWorkOrder::where('tenant_id', $this->tenantContext->getTenantId());

        foreach (['status', 'asset_id', 'maintenance_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        return MaintenanceWorkOrderResource::collection($query->orderByDesc('scheduled_date')->paginate($this->perPage($request)));
    }

    /**
     * Creates a work order.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders',
        operationId: 'storeWorkOrderEamMaintenanceWorkOrders',
        description: 'Creates a work order. Corrective, emergency and critical work orders put the asset under maintenance; above the threshold an active LARGE_MAINTENANCE_APPROVAL workflow must approve it first.',
        summary: 'Create maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStoreMaintenanceWorkOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Work order created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function storeWorkOrder(StoreMaintenanceWorkOrderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $workOrder = $this->maintenanceService->createWorkOrder(new CreateMaintenanceWorkOrderDTO(
            tenantId: (string) $this->tenantContext->getTenantId(),
            assetId: (string) $data['asset_id'],
            maintenanceType: (string) ($data['maintenance_type'] ?? 'preventive'),
            priority: (string) ($data['priority'] ?? 'medium'),
            scheduledDate: $data['scheduled_date'] ?? null,
            planId: $data['plan_id'] ?? null,
            assignedTechnicianId: $data['assigned_technician_id'] ?? null,
            problemDescription: $data['problem_description'] ?? null,
            estimatedCost: (string) ($data['estimated_cost'] ?? '0')
        ), $this->actor($request));

        return (new MaintenanceWorkOrderResource($workOrder))->response()->setStatusCode(201);
    }

    /**
     * One work order with its spare parts.
     */
    #[OA\Get(
        path: '/api/v1/eam/maintenance/work-orders/{id}',
        operationId: 'getShowWorkOrderEamMaintenanceWorkOrders',
        description: 'Returns a work order of the active company with the spare parts issued to it.',
        summary: 'Get maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamWorkOrderId')],
        responses: [
            new OA\Response(response: 200, description: 'Work order', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource'),
                new OA\Property(property: 'parts', type: 'array', items: new OA\Items(ref: '#/components/schemas/AssetManagementWorkOrderPartResource')),
            ])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
        ]
    )]
    public function showWorkOrder(string $id): JsonResponse
    {
        $workOrder = $this->workOrder($id);
        $parts = WorkOrderPart::where('tenant_id', $workOrder->tenant_id)->where('work_order_id', $workOrder->id)->orderBy('consumed_at')->get();

        return response()->json([
            'data' => new MaintenanceWorkOrderResource($workOrder),
            'parts' => WorkOrderPartResource::collection($parts),
        ]);
    }

    /**
     * Starts a work order.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders/{id}/start',
        operationId: 'startEamMaintenanceWorkOrder',
        description: 'Starts a scheduled work order; with stops_asset (default true) the downtime of the asset starts and it goes under maintenance.',
        summary: 'Start maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamWorkOrderId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementStartWorkOrderRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Work order started', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function startWorkOrder(StartWorkOrderRequest $request, string $id): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->maintenanceService->startWorkOrder($this->workOrder($id), (bool) ($request->validated('stops_asset') ?? true), $this->actor($request)));
    }

    /**
     * Issues a spare part from the inventory to a work order.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders/{id}/parts',
        operationId: 'consumeEamMaintenanceWorkOrderPart',
        description: 'Issues a product of the company from the inventory (a stock out movement for products with inventory tracking) at its cost price; the cost is added to the parts cost of the work order.',
        summary: 'Issue spare part',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamWorkOrderId')],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementConsumePartRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Part issued', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementWorkOrderPartResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function consumePart(ConsumePartRequest $request, string $id): JsonResponse
    {
        $data = $request->validated();
        $part = $this->maintenanceService->consumePart($this->workOrder($id), (string) $data['product_id'], (string) $data['quantity'], $data['warehouse_id'] ?? null, $this->actor($request));

        return (new WorkOrderPartResource($part))->response()->setStatusCode(201);
    }

    /**
     * Completes a work order.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders/{id}/complete',
        operationId: 'completeWorkOrderEamMaintenanceWorkOrder',
        description: 'Completes a work order with labour, parts and external costs and downtime (computed from the start when not given). capitalize_as_upgrade adds the cost to the asset books and posts it; the plan moves to its next due date and meter.',
        summary: 'Complete maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamWorkOrderId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementCompleteMaintenanceWorkOrderRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Work order completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function completeWorkOrder(CompleteMaintenanceWorkOrderRequest $request, string $id): MaintenanceWorkOrderResource
    {
        $workOrder = $this->workOrder($id);
        $data = $request->validated();

        return new MaintenanceWorkOrderResource($this->maintenanceService->completeWorkOrder(new CompleteMaintenanceWorkOrderDTO(
            workOrderId: (string) $workOrder->id,
            downtimeHours: (string) ($data['downtime_hours'] ?? '0'),
            laborCost: (string) ($data['labor_cost'] ?? '0'),
            partsCost: (string) ($data['parts_cost'] ?? '0'),
            externalServiceCost: (string) ($data['external_service_cost'] ?? '0'),
            capitalizeAsUpgrade: (bool) ($data['capitalize_as_upgrade'] ?? false),
            conditionAfterMaintenance: (string) ($data['condition_after_maintenance'] ?? 'good'),
            resolutionNotes: $data['resolution_notes'] ?? null,
            partsConsumed: $data['parts_consumed'] ?? [],
            meterReading: isset($data['meter_reading']) ? (string) $data['meter_reading'] : null
        ), $this->actor($request)));
    }

    /**
     * Cancels a work order.
     */
    #[OA\Post(
        path: '/api/v1/eam/maintenance/work-orders/{id}/cancel',
        operationId: 'cancelEamMaintenanceWorkOrder',
        description: 'Cancels a work order that is not completed and has no issued parts; the asset goes back to its previous status.',
        summary: 'Cancel maintenance work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['EAM Maintenance'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/EamWorkOrderId')],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/AssetManagementCancelWorkOrderRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Work order cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AssetManagementMaintenanceWorkOrderResource')])),
            new OA\Response(ref: '#/components/responses/EamUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/EamForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/EamNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/EamUnprocessable', response: 422),
        ]
    )]
    public function cancelWorkOrder(CancelWorkOrderRequest $request, string $id): MaintenanceWorkOrderResource
    {
        return new MaintenanceWorkOrderResource($this->maintenanceService->cancelWorkOrder($this->workOrder($id), $request->validated('reason'), $this->actor($request)));
    }

    /**
     * The plan of the active company, 404 otherwise.
     */
    private function plan(string $id): MaintenancePlan
    {
        return MaintenancePlan::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }

    /**
     * The work order of the active company, 404 otherwise.
     */
    private function workOrder(string $id): MaintenanceWorkOrder
    {
        return MaintenanceWorkOrder::where('tenant_id', $this->tenantContext->getTenantId())->findOrFail($id);
    }

    /**
     * Rows per page, between 1 and 100.
     */
    private function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', '25')));
    }

    /**
     * The authenticated user id.
     */
    private function actor(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
