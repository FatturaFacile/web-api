<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Actions\DispatchWorkOrderAction;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\RecordVisitDTO;
use Modules\ServiceManagement\DTOs\ScheduleAppointmentDTO;
use Modules\ServiceManagement\Http\Requests\RecordVisitRequest;
use Modules\ServiceManagement\Http\Requests\ScheduleAppointmentRequest;
use Modules\ServiceManagement\Http\Requests\StoreWorkOrderRequest;
use Modules\ServiceManagement\Http\Resources\WorkOrderResource;
use Modules\ServiceManagement\Models\WorkOrder;
use OpenApi\Attributes as OA;

final class WorkOrderController extends Controller
{
    public function __construct(
        private readonly WorkOrderAndFieldServiceInterface $workOrderService,
        private readonly DispatchWorkOrderAction $dispatchAction,
        private readonly TenantContext $tenantContext
    ) {}

    /**
     * Returns the paginated list of work orders of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/esm/work-orders',
        operationId: 'listEsmWorkOrders',
        description: 'Returns the paginated list of work orders of the active tenant. Results can be narrowed with the query parameters.',
        summary: 'List work orders',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter by status', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'work_type', in: 'query', required: false, description: 'Filter by work type', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of work orders', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ServiceManagementWorkOrderResource')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = WorkOrder::where('tenant_id', $tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('work_type')) {
            $query->where('work_type', $request->query('work_type'));
        }

        $workOrders = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => WorkOrderResource::collection($workOrders),
            'meta' => [
                'current_page' => $workOrders->currentPage(),
                'total' => $workOrders->total(),
            ],
        ]);
    }

    /**
     * Creates a new work order in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders',
        operationId: 'createEsmWorkOrder',
        description: 'Creates a new work order in the active tenant.',
        summary: 'Create work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementStoreWorkOrderRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Work order created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreWorkOrderRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new CreateWorkOrderDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            title: $validated['title'],
            description: $validated['description'] ?? null,
            workType: $validated['work_type'] ?? 'corrective',
            priority: $validated['priority'] ?? 'medium',
            serviceCategoryId: $validated['service_category_id'] ?? null,
            customerId: $validated['customer_id'] ?? null,
            serviceContractId: $validated['service_contract_id'] ?? null,
            assetId: $validated['asset_id'] ?? null,
            ticketId: $validated['ticket_id'] ?? null,
            projectId: $validated['project_id'] ?? null,
            serviceTeamId: $validated['service_team_id'] ?? null,
            leadTechnicianId: $validated['lead_technician_id'] ?? null,
            scheduledStart: $validated['scheduled_start'] ?? null,
            scheduledEnd: $validated['scheduled_end'] ?? null,
            locationAddress: $validated['location_address'] ?? null,
            billingType: $validated['billing_type'] ?? 'contract_included',
            items: $request->input('items', []),
        );

        $workOrder = $this->workOrderService->createWorkOrder($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Work order created successfully',
            'data' => new WorkOrderResource($workOrder),
        ], 201);
    }

    /**
     * Returns a single work order of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/esm/work-orders/{workOrder}',
        operationId: 'getEsmWorkOrder',
        description: 'Returns a single work order of the active tenant.',
        summary: 'Get work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(WorkOrder $workOrder): JsonResponse
    {
        return response()->json([
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    /**
     * Dispatches a work order to a technician.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders/{workOrder}/dispatch',
        operationId: 'dispatchEsmWorkOrder',
        description: 'Dispatches a work order to a technician.',
        summary: 'Dispatch work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'technician_id')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function dispatch(WorkOrder $workOrder, Request $request): JsonResponse
    {
        $technicianId = $request->input('technician_id');
        $updated = $this->dispatchAction->execute($workOrder->id, $technicianId, $request->user()?->id);

        return response()->json([
            'message' => 'Work order dispatched successfully',
            'data' => new WorkOrderResource($updated),
        ]);
    }

    /**
     * Schedules a service appointment for a work order.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders/{workOrder}/schedule',
        operationId: 'scheduleEsmWorkOrder',
        description: 'Schedules a service appointment for a work order.',
        summary: 'Schedule work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementScheduleAppointmentRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementServiceAppointmentModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function schedule(WorkOrder $workOrder, ScheduleAppointmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = new ScheduleAppointmentDTO(
            workOrderId: $workOrder->id,
            technicianId: $validated['technician_id'],
            scheduledStart: $validated['scheduled_start'],
            scheduledEnd: $validated['scheduled_end'],
            notes: $validated['notes'] ?? null,
        );

        $appointment = $this->workOrderService->scheduleAppointment($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Appointment scheduled successfully',
            'data' => $appointment,
        ]);
    }

    /**
     * Records a technician visit on a work order.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders/{workOrder}/record-visit',
        operationId: 'recordVisitEsmWorkOrder',
        description: 'Records a technician visit on a work order.',
        summary: 'Record work order visit',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ServiceManagementRecordVisitRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementServiceVisitModel')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function recordVisit(WorkOrder $workOrder, RecordVisitRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dto = new RecordVisitDTO(
            workOrderId: $workOrder->id,
            serviceAppointmentId: $validated['service_appointment_id'] ?? null,
            technicianId: $validated['technician_id'] ?? ($workOrder->lead_technician_id ?? ''),
            checkInAt: $validated['check_in_at'] ?? now()->toIso8601String(),
            checkOutAt: $validated['check_out_at'] ?? now()->toIso8601String(),
            workSummary: $validated['work_summary'] ?? null,
            customerFeedback: $validated['customer_feedback'] ?? null,
        );

        $visit = $this->workOrderService->recordVisit($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Service visit recorded successfully',
            'data' => $visit,
        ]);
    }

    /**
     * Completes a work order with the completion notes and the customer signature.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders/{workOrder}/complete',
        operationId: 'completeEsmWorkOrder',
        description: 'Completes a work order with the completion notes and the customer signature.',
        summary: 'Complete work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'completion_notes'), new OA\Property(property: 'resolution_summary'), new OA\Property(property: 'customer_signature_url'), new OA\Property(property: 'signed_by_customer_name'), new OA\Property(property: 'signed_at')])),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function complete(WorkOrder $workOrder, Request $request): JsonResponse
    {
        $dto = new CompleteWorkOrderDTO(
            workOrderId: $workOrder->id,
            completionNotes: $request->input('completion_notes'),
            resolutionSummary: $request->input('resolution_summary'),
            customerSignatureUrl: $request->input('customer_signature_url'),
            signedByCustomerName: $request->input('signed_by_customer_name'),
            signedAt: $request->input('signed_at')
        );

        $completed = $this->workOrderService->completeWorkOrder($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Work order completed successfully',
            'data' => new WorkOrderResource($completed),
        ]);
    }
}
