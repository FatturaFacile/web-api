<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\RecordVisitDTO;
use Modules\ServiceManagement\DTOs\ScheduleAppointmentDTO;
use Modules\ServiceManagement\Events\ServiceCostReadyEvent;
use Modules\ServiceManagement\Events\ServiceInvoiceReadyEvent;
use Modules\ServiceManagement\Events\ServiceLaborCostReadyEvent;
use Modules\ServiceManagement\Events\ServiceVisitCompletedEvent;
use Modules\ServiceManagement\Events\WorkOrderCompletedEvent;
use Modules\ServiceManagement\Events\WorkOrderCreatedEvent;
use Modules\ServiceManagement\Models\PartsConsumption;
use Modules\ServiceManagement\Models\ServiceAppointment;
use Modules\ServiceManagement\Models\ServiceTechnician;
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
            $woNumber = 'WO-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $customerId = $dto->customerId ?: (string) Str::uuid7();

            $workOrder = WorkOrder::create([
                'tenant_id' => $dto->tenantId,
                'company_id' => $dto->companyId,
                'work_order_number' => $woNumber,
                'title' => $dto->title,
                'description' => $dto->description ?? $dto->title,
                'work_type' => $dto->workType,
                'priority' => $dto->priority,
                'status' => 'draft',
                'customer_id' => $customerId,
                'service_contract_id' => $dto->serviceContractId,
                'asset_id' => $dto->assetId,
                'ticket_id' => $dto->ticketId,
                'project_id' => $dto->projectId,
                'service_team_id' => $dto->serviceTeamId,
                'assigned_technician_id' => $dto->leadTechnicianId,
                'scheduled_start' => $dto->scheduledStart,
                'scheduled_end' => $dto->scheduledEnd,
                'location' => ['address' => $dto->locationAddress],
            ]);

            foreach ($dto->items as $item) {
                $qty = (float) ($item['quantity'] ?? 1.0);
                $uCost = (float) ($item['unit_cost'] ?? 0.0);
                $uPrice = (float) ($item['unit_price'] ?? 0.0);

                WorkOrderItem::create([
                    'tenant_id' => $dto->tenantId,
                    'company_id' => $dto->companyId,
                    'work_order_id' => $workOrder->id,
                    'item_type' => $item['item_type'] ?? 'labor',
                    'description' => $item['description'] ?? 'Item',
                    'quantity' => $qty,
                    'unit_cost' => $uCost,
                    'total_cost' => round($qty * $uCost, 2),
                    'unit_price' => $uPrice,
                    'total_price' => round($qty * $uPrice, 2),
                    'is_billable' => (bool) ($item['is_billable'] ?? true),
                ]);

                if (($item['item_type'] ?? 'labor') === 'labor') {
                    $workOrder->labor_cost = (float) $workOrder->labor_cost + round($qty * $uCost, 2);
                } else {
                    $workOrder->material_cost = (float) $workOrder->material_cost + round($qty * $uCost, 2);
                }
                $workOrder->total_cost = (float) $workOrder->total_cost + round($qty * $uCost, 2);
                $workOrder->total_billable_amount = (float) $workOrder->total_billable_amount + round($qty * $uPrice, 2);
            }
            $workOrder->save();

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
            $appNumber = 'APT-'.date('Ymd').'-'.strtoupper(Str::random(5));

            $appointment = ServiceAppointment::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'appointment_number' => $appNumber,
                'work_order_id' => $workOrder->id,
                'technician_id' => $dto->technicianId,
                'customer_id' => $workOrder->customer_id,
                'start_time' => $dto->scheduledStart,
                'end_time' => $dto->scheduledEnd,
                'status' => 'scheduled',
                'notes' => $dto->notes,
            ]);

            if (in_array($workOrder->status, ['draft', 'approved', 'dispatched'], true)) {
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
            $visitNumber = 'VIS-'.date('Ymd').'-'.strtoupper(Str::random(5));

            $technicianId = $dto->technicianId !== '' ? $dto->technicianId : $workOrder->assigned_technician_id;

            if (! $technicianId) {
                $tech = ServiceTechnician::firstOrCreate(
                    ['tenant_id' => $workOrder->tenant_id, 'name' => 'Field Technician'],
                    [
                        'company_id' => $workOrder->company_id,
                        'email' => 'tech.'.Str::random(5).'@fieldservice.local',
                        'hourly_cost_rate' => 35.00,
                        'hourly_billable_rate' => 85.00,
                    ]
                );
                $technicianId = $tech->id;
            }

            $checkIn = $dto->checkInAt !== '' ? $dto->checkInAt : now()->toIso8601String();
            $checkOut = $dto->checkOutAt ?? now()->toIso8601String();

            $visit = ServiceVisit::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'visit_number' => $visitNumber,
                'work_order_id' => $workOrder->id,
                'appointment_id' => $dto->serviceAppointmentId,
                'technician_id' => $technicianId,
                'check_in_at' => $checkIn,
                'check_out_at' => $checkOut,
                'visit_summary' => $dto->workSummary,
                'is_successful' => true,
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
                'product_id' => (string) Str::uuid7(),
                'warehouse_id' => $warehouseId,
                'lot_number' => $itemNumber,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'metadata' => [
                    'item_number' => $itemNumber,
                    'description' => $description,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                ],
            ]);

            $workOrder->material_cost = (float) $workOrder->material_cost + $totalCost;
            $workOrder->total_cost = (float) $workOrder->total_cost + $totalCost;
            $workOrder->total_billable_amount = (float) $workOrder->total_billable_amount + $totalPrice;
            $workOrder->save();

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
                'resolution_notes' => $dto->resolutionSummary,
                'signature_data' => $dto->customerSignatureUrl,
                'signed_by_name' => $dto->signedByCustomerName,
                'signed_at' => $dto->signedAt ?? now(),
                'customer_signed' => ! empty($dto->signedByCustomerName),
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
                    technicianId: $workOrder->assigned_technician_id ?? '',
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
            $workOrder->is_invoice_ready = true;
            $workOrder->save();

            $this->dispatcher->dispatch(new ServiceInvoiceReadyEvent(
                workOrderId: $workOrder->id,
                workOrderNumber: $workOrder->work_order_number,
                customerId: (string) $workOrder->customer_id,
                totalAmount: (float) $workOrder->total_billable_amount,
                tenantId: $workOrder->tenant_id,
                companyId: $workOrder->company_id
            ));
        }
    }
}
