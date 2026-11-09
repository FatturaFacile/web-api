<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\Actions\DispatchWorkOrderAction;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\RecordVisitDTO;
use Modules\ServiceManagement\DTOs\ScheduleAppointmentDTO;
use Modules\ServiceManagement\Http\Requests\CompleteWorkOrderRequest;
use Modules\ServiceManagement\Http\Requests\ConsumeWorkOrderPartRequest;
use Modules\ServiceManagement\Http\Requests\DispatchWorkOrderRequest;
use Modules\ServiceManagement\Http\Requests\InvoiceWorkOrderRequest;
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
            items: $validated['items'] ?? [],
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
    public function dispatch(WorkOrder $workOrder, DispatchWorkOrderRequest $request): JsonResponse
    {
        $technicianId = $request->validated('technician_id');
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
    public function complete(WorkOrder $workOrder, CompleteWorkOrderRequest $request): JsonResponse
    {
        $dto = new CompleteWorkOrderDTO(
            workOrderId: $workOrder->id,
            completionNotes: $request->validated('completion_notes'),
            resolutionSummary: $request->validated('resolution_summary'),
            customerSignatureUrl: $request->validated('customer_signature_url'),
            signedByCustomerName: $request->validated('signed_by_customer_name'),
            signedAt: $request->validated('signed_at')
        );

        $completed = $this->workOrderService->completeWorkOrder($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Work order completed successfully',
            'data' => new WorkOrderResource($completed),
        ]);
    }

    /**
     * Issues a stocked part to a work order.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders/{workOrder}/parts',
        operationId: 'consumePartEsmWorkOrder',
        description: 'Issues a part of the company stock to a scheduled, dispatched or in-progress work order. The product is found by its SKU (`item_number`); when it tracks inventory the quantity leaves the warehouse through the inventory service. Cost and price default to the cost and sale price of the product. The work order cost and billable totals grow by the line, in exact decimal arithmetic.',
        summary: 'Consume a part on a work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['item_number', 'quantity'],
            properties: [
                new OA\Property(property: 'item_number', description: 'SKU of a product of the company', type: 'string', example: 'BRUSH-ALT-88'),
                new OA\Property(property: 'quantity', type: 'string', example: '2'),
                new OA\Property(property: 'unit_cost', description: 'Defaults to the product cost price', type: 'string', nullable: true, example: '15.00'),
                new OA\Property(property: 'unit_price', description: 'Defaults to the product sale price', type: 'string', nullable: true, example: '35.00'),
                new OA\Property(property: 'description', type: 'string', nullable: true),
                new OA\Property(property: 'warehouse_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Part consumed', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', ref: '#/components/schemas/ServiceManagementWorkOrderResource')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden: read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Work order not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown item number, work order not open, or invalid quantity', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function consumePart(WorkOrder $workOrder, ConsumeWorkOrderPartRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $product = Product::where('sku', $validated['item_number'])->first();

        $this->workOrderService->consumePart(
            workOrderId: $workOrder->id,
            itemNumber: $validated['item_number'],
            description: $validated['description'] ?? (string) ($product?->name ?? $validated['item_number']),
            quantity: $validated['quantity'],
            unitCost: $validated['unit_cost'] ?? (string) ($product?->cost_price ?? '0'),
            unitPrice: $validated['unit_price'] ?? (string) ($product?->sale_price ?? '0'),
            warehouseId: $validated['warehouse_id'] ?? null,
            actorUserId: $request->user()?->id
        );

        return response()->json([
            'message' => 'Part consumed successfully',
            'data' => new WorkOrderResource($workOrder->refresh()),
        ], 201);
    }

    /**
     * Drafts the sales invoice of a completed work order.
     */
    #[OA\Post(
        path: '/api/v1/esm/work-orders/{workOrder}/invoice',
        operationId: 'invoiceEsmWorkOrder',
        description: 'Drafts the sales invoice of a completed, billable work order through the invoicing service: one line per billable item and per consumed part, at the recorded prices, with the VAT rate stated in the request. The draft then follows the normal invoice flow (approval, posting, e-invoicing). The work order keeps `invoice_id`, is marked invoiced and refuses a second invoice.',
        summary: 'Invoice a work order',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['ESM Work Orders'],
        parameters: [
            new OA\Parameter(name: 'workOrder', in: 'path', required: true, description: 'Identifier of the work order', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['tax_rate'],
            properties: [
                new OA\Property(property: 'tax_rate', description: 'VAT rate in percent applied to every line', type: 'string', example: '22'),
                new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'revenue_account_id', type: 'string', format: 'uuid', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Invoice drafted', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'invoice_id', type: 'string', format: 'uuid'), new OA\Property(property: 'invoice_number', type: 'string'), new OA\Property(property: 'total_gross', type: 'string', example: '183.00')])])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden: read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Work order not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Not completed, not billable, internal, already invoiced or nothing to invoice', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function invoice(WorkOrder $workOrder, InvoiceWorkOrderRequest $request): JsonResponse
    {
        $validated = $request->validated();

        /** @var SalesInvoice $invoice */
        $invoice = $this->workOrderService->invoiceWorkOrder(
            $workOrder->id,
            (string) $validated['tax_rate'],
            $validated['due_date'] ?? null,
            $validated['revenue_account_id'] ?? null,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Invoice drafted from work order',
            'data' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'total_gross' => (string) $invoice->total_gross,
            ],
        ], 201);
    }
}
