<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\RecordVisitDTO;
use Modules\ServiceManagement\DTOs\ScheduleAppointmentDTO;
use Modules\ServiceManagement\Events\FieldServiceExpenseReadyEvent;
use Modules\ServiceManagement\Events\ServiceCostReadyEvent;
use Modules\ServiceManagement\Events\ServiceInvoiceReadyEvent;
use Modules\ServiceManagement\Events\ServiceLaborCostReadyEvent;
use Modules\ServiceManagement\Events\ServiceVisitCompletedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Events\WorkOrderCreatedEvent;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\ServiceAppointment;
use Modules\ServiceManagement\Models\ServiceVisit;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\ServiceManagement\Models\WorkOrderItem;

final readonly class WorkOrderAndFieldService implements WorkOrderAndFieldServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function createWorkOrder(CreateWorkOrderDTO $dto, ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($dto) {
            $woNumber = 'WO-' . date('Ymd') . '-' . strtoupper(Str::random(6));

            $workOrder = WorkOrder::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'work_order_number' => $woNumber,
                'title' => $dto->title,
                'description' => $dto->description,
                'work_type' => $dto->workType,
                'priority' => $dto->priority,
                'status' => 'draft',
                'service_category_id' => $dto->serviceCategoryId,
                'customer_id' => $dto->customerId,
                'service_contract_id' => $dto->serviceContractId,
                'asset_id' => $dto->assetId,
                'ticket_id' => $dto->ticketId,
                'project_id' => $dto->projectId,
                'service_team_id' => $dto->serviceTeamId,
                'lead_technician_id' => $dto->leadTechnicianId,
                'scheduled_start' => $dto->scheduledStart,
                'scheduled_end' => $dto->scheduledEnd,
                'location_address' => $dto->locationAddress,
                'billing_type' => $dto->billingType,
            ]);

            foreach ($dto->items as $item) {
                WorkOrderItem::create([
                    'tenant_id' => $dto->tenantId,
                    'company_id' => $dto->companyId,
                    'work_order_id' => $workOrder->id,
                    'item_type' => $item['item_type'] ?? 'labor',
                    'item_number' => $item['item_number'] ?? null,
                    'description' => $item['description'] ?? 'Item',
                    'quantity' => (float) ($item['quantity'] ?? 1.0),
                    'unit_cost' => (float) ($item['unit_cost'] ?? 0.0),
                    'total_cost' => (float) (($item['quantity'] ?? 1.0) * ($item['unit_cost'] ?? 0.0)),
                    'unit_price' => (float) ($item['unit_price'] ?? 0.0),
                    'total_price' => (float) (($item['quantity'] ?? 1.0) * ($item['unit_price'] ?? 0.0)),
                    'is_billable' => (bool) ($item['is_billable'] ?? true),
                ]);
            }

            $this->auditLedger->record(
                'WORK_ORDER_CREATED',
                $workOrder,
                null,
                ['work_order_number' => $woNumber, 'work_type' => $dto->workType],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new WorkOrderCreatedEvent(
                workOrderId: $workOrder->id,
                workOrderNumber: $workOrder->work_order_number,
                title: $workOrder->title,
                workType: $workOrder->work_type,
                assetId: $workOrder->asset_id,
                customerId: $workOrder->customer_id,
                tenantId: $workOrder->tenant_id,
                companyId: $workOrder->company_id
            ));

            return $workOrder;
        });
    }

    public function scheduleAppointment(ScheduleAppointmentDTO $dto, ?string $actorUserId = null): ServiceAppointment
    {
        return DB::transaction(function () use ($dto) {
            $workOrder = WorkOrder::findOrFail($dto->workOrderId);
            $appNumber = 'APT-' . date('Ymd') . '-' . strtoupper(Str::random(5));

            $appointment = ServiceAppointment::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'appointment_number' => $appNumber,
                'work_order_id' => $workOrder->id,
                'technician_id' => $dto->technicianId,
                'scheduled_start' => $dto->scheduledStart,
                'scheduled_end' => $dto->scheduledEnd,
                'status' => 'scheduled',
                'notes' => $dto->notes,
            ]);

            if (in_array($workOrder->status, ['draft', 'approved'], true)) {
                $workOrder->update(['status' => 'scheduled']);
            }

            $this->auditLedger->record(
                'SERVICE_APPOINTMENT_SCHEDULED',
                $appointment,
                null,
                ['appointment_number' => $appNumber, 'technician_id' => $dto->technicianId],
                'MODULE_17_ESM'
            );

            return $appointment;
        });
    }

    public function recordVisit(RecordVisitDTO $dto, ?string $actorUserId = null): ServiceVisit
    {
        return DB::transaction(function () use ($dto) {
            $workOrder = WorkOrder::findOrFail($dto->workOrderId);
            $visitNumber = 'VIS-' . date('Ymd') . '-' . strtoupper(Str::random(5));

            $checkIn = $dto->checkInAt !== '' ? $dto->checkInAt : now()->toIso8601String();
            $checkOut = $dto->checkOutAt ?? now()->toIso8601String();

            $visit = ServiceVisit::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'visit_number' => $visitNumber,
                'work_order_id' => $workOrder->id,
                'service_appointment_id' => $dto->serviceAppointmentId,
                'technician_id' => $dto->technicianId !== '' ? $dto->technicianId : $workOrder->lead_technician_id,
                'status' => 'completed',
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'work_summary' => $dto->workSummary,
                'customer_feedback' => $dto->customerFeedback,
            ]);

            if ($dto->serviceAppointmentId) {
                ServiceAppointment::where('id', $dto->serviceAppointmentId)->update(['status' => 'completed']);
            }

            $workOrder->update(['status' => 'in_progress']);

            $this->auditLedger->record(
                'SERVICE_VISIT_RECORDED',
                $visit,
                null,
                ['visit_number' => $visitNumber],
                'MODULE_17_ESM'
            );

            $this->dispatcher->dispatch(new ServiceVisitCompletedEvent(
                serviceVisitId: $visit->id,
                visitNumber: $visit->visit_number,
                workOrderId: $workOrder->id,
                technicianId: $visit->technician_id ?? '',
                tenantId: $workOrder->tenant_id,
                companyId: $workOrder->company_id
            ));

            return $visit;
        });
    }

    public function consumePart(
        string $workOrderId,
        string $itemNumber,
        string $description,
        float $quantity,
        float $unitCost,
        float $unitPrice,
        ?string $warehouseId = null,
        ?string $actorUserId = null
    ): PartsConsumption {
        return DB::transaction(function () use ($workOrderId, $itemNumber, $description, $quantity, $unitCost, $unitPrice, $warehouseId) {
            $workOrder = WorkOrder::findOrFail($workOrderId);

            $totalCost = round($quantity * $unitCost, 2);
            $totalPrice = round($quantity * $unitPrice, 2);

            $consumption = PartsConsumption::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'work_order_id' => $workOrder->id,
                'warehouse_id' => $warehouseId,
                'item_number' => $itemNumber,
                'description' => $description,
                'quantity_consumed' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'unit_price' => $unitPrice,
                'total_price' => $totalPrice,
                'consumed_at' => now(),
            ]);

            $workOrder->increment('total_parts_cost', $totalCost);
            $workOrder->increment('total_cost', $totalCost);
            $workOrder->increment('total_billed_amount', $totalPrice);

            $this->auditLedger->record(
                'PARTS_CONSUMED',
                $consumption,
                null,
                ['item_number' => $itemNumber, 'quantity' => $quantity, 'total_cost' => $totalCost],
                'MODULE_17_ESM'
            );

            return $consumption;
        });
    }

    public function completeWorkOrder(CompleteWorkOrderDTO $dto, ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($dto) {
            $workOrder = WorkOrder::findOrFail($dto->workOrderId);

            if ($workOrder->status === 'completed') {
                return $workOrder;
            }

            $oldValues = ['status' => $workOrder->status];

            $workOrder->update([
                'status' => 'completed',
                'actual_end' => now(),
                'resolution_summary' => $dto->resolutionSummary,
                'customer_signature_url' => $dto->customerSignatureUrl,
                'signed_by_customer_name' => $dto->signedByCustomerName,
                'signed_at' => $dto->signedAt ?? now(),
            ]);

            $this->auditLedger->record(
                'WORK_ORDER_COMPLETED',
                $workOrder,
                $oldValues,
                ['resolution_summary' => $dto->resolutionSummary],
                'MODULE_17_ESM'
            );

            // Dispatch domain event
            $this->dispatcher->dispatch(new WorkOrderCompletedEvent(
                workOrderId: $workOrder->id,
                workOrderNumber: $workOrder->work_order_number,
                totalCost: (float) $workOrder->total_cost,
                totalLaborHours: (float) $workOrder->total_labor_hours,
                tenantId: $workOrder->tenant_id,
                companyId: $workOrder->company_id
            ));

            // Dispatch financial integration events
            $this->dispatcher->dispatch(new ServiceCostReadyEvent(
                workOrderId: $workOrder->id,
                totalCost: (float) $workOrder->total_cost,
                costCenterId: null,
                projectId: $workOrder->project_id,
                completionDate: now()->toDateString(),
                tenantId: $workOrder->tenant_id,
                companyId: $workOrder->company_id
            ));

            if ($workOrder->total_labor_cost > 0) {
                $this->dispatcher->dispatch(new ServiceLaborCostReadyEvent(
                    workOrderId: $workOrder->id,
                    technicianId: $workOrder->lead_technician_id ?? '',
                    hoursWorked: (float) $workOrder->total_labor_hours,
                    laborCost: (float) $workOrder->total_labor_cost,
                    workDate: now()->toDateString(),
                    tenantId: $workOrder->tenant_id,
                    companyId: $workOrder->company_id
                ));
            }

            return $workOrder;
        });
    }

    public function markInvoiceReady(string $workOrderId, ?string $actorUserId = null): void
    {
        $workOrder = WorkOrder::findOrFail($workOrderId);

        if (! $workOrder->is_invoice_ready) {
            $workOrder->update(['is_invoice_ready' => true]);

            $this->dispatcher->dispatch(new ServiceInvoiceReadyEvent(
                workOrderId: $workOrder->id,
                workOrderNumber: $workOrder->work_order_number,
                customerId: $workOrder->customer_id ?? '',
                totalAmount: (float) $workOrder->total_billed_amount,
                tenantId: $workOrder->tenant_id,
                companyId: $workOrder->company_id
            ));
        }
    }
}
