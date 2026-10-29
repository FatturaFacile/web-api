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

final class WorkOrderController extends Controller
{
    public function __construct(
        private readonly WorkOrderAndFieldServiceInterface $workOrderService,
        private readonly DispatchWorkOrderAction $dispatchAction,
        private readonly TenantContext $tenantContext
    ) {}

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

    public function show(WorkOrder $workOrder): JsonResponse
    {
        return response()->json([
            'data' => new WorkOrderResource($workOrder),
        ]);
    }

    public function dispatch(WorkOrder $workOrder, Request $request): JsonResponse
    {
        $technicianId = $request->input('technician_id');
        $updated = $this->dispatchAction->execute($workOrder->id, $technicianId, $request->user()?->id);

        return response()->json([
            'message' => 'Work order dispatched successfully',
            'data' => new WorkOrderResource($updated),
        ]);
    }

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
