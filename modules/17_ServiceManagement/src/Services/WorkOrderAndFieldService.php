<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantClock;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
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
        private AuditLedgerInterface $auditLedger,
        private StockService $stock,
        private InvoicingServiceInterface $invoicing,
        private TenantClock $clock,
    ) {}

    public function createWorkOrder(CreateWorkOrderDTO $dto, ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($dto) {
            $woNumber = 'WO-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $customerId = $dto->customerId ?: null;

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
                $qty = ServiceAmounts::text($item['quantity'] ?? '1');
                $uCost = ServiceAmounts::money($item['unit_cost'] ?? '0');
                $uPrice = ServiceAmounts::money($item['unit_price'] ?? '0');
                $lineCost = ServiceAmounts::lineTotal($qty, $uCost);
                $linePrice = ServiceAmounts::lineTotal($qty, $uPrice);
                $isBillable = (bool) ($item['is_billable'] ?? true);

                WorkOrderItem::create([
                    'tenant_id' => $dto->tenantId,
                    'company_id' => $dto->companyId,
                    'work_order_id' => $workOrder->id,
                    'item_type' => $item['item_type'] ?? 'labor',
                    'description' => $item['description'] ?? 'Item',
                    'quantity' => $qty,
                    'unit_cost' => $uCost,
                    'total_cost' => $lineCost,
                    'unit_price' => $uPrice,
                    'total_price' => $linePrice,
                    'is_billable' => $isBillable,
                ]);

                if (($item['item_type'] ?? 'labor') === 'labor') {
                    $workOrder->labor_cost = ServiceAmounts::add($workOrder->labor_cost, $lineCost);
                } else {
                    $workOrder->material_cost = ServiceAmounts::add($workOrder->material_cost, $lineCost);
                }
                $workOrder->total_cost = ServiceAmounts::add($workOrder->total_cost, $lineCost);

                if ($isBillable) {
                    $workOrder->total_billable_amount = ServiceAmounts::add($workOrder->total_billable_amount, $linePrice);
                }
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
                throw ValidationException::withMessages(['technician_id' => __('work_orders.visit_requires_technician')]);
            }

            ServiceTechnician::where('tenant_id', $workOrder->tenant_id)->findOrFail($technicianId);

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
        string|int|float $quantity,
        string|int|float $unitCost,
        string|int|float $unitPrice,
        ?string $warehouseId = null,
        ?string $actorUserId = null
    ): PartsConsumption {
        return DB::transaction(function () use ($workOrderId, $itemNumber, $description, $quantity, $unitCost, $unitPrice, $warehouseId) {
            $workOrder = WorkOrder::whereKey($workOrderId)->lockForUpdate()->firstOrFail();

            if (! in_array($workOrder->status, ['scheduled', 'dispatched', 'in_progress'], true)) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.part_not_open')]);
            }

            $quantity = ServiceAmounts::text($quantity);

            if (! Decimal::isPositive($quantity)) {
                throw ValidationException::withMessages(['quantity' => __('work_orders.part_quantity_positive')]);
            }

            // The part is a real product of the company: its stock leaves through the inventory service, like every
            // other issue, instead of a consumption that points to an identifier nobody owns.
            $product = Product::where('tenant_id', $workOrder->tenant_id)->where('sku', $itemNumber)->first()
                ?? throw ValidationException::withMessages(['item_number' => __('work_orders.part_unknown_product')]);
            $warehouse = $warehouseId !== null ? Warehouse::where('tenant_id', $workOrder->tenant_id)->findOrFail($warehouseId) : null;

            $movement = $product->track_inventory
                ? $this->stock->record((string) $product->id, 'out', (float) $quantity, $workOrder, $warehouse, (float) ServiceAmounts::money($unitCost), "Service work order {$workOrder->work_order_number}")
                : null;

            $unitCost = ServiceAmounts::money($unitCost);
            $unitPrice = ServiceAmounts::money($unitPrice);
            $totalCost = ServiceAmounts::lineTotal($quantity, $unitCost);
            $totalPrice = ServiceAmounts::lineTotal($quantity, $unitPrice);

            $consumption = PartsConsumption::create([
                'tenant_id' => $workOrder->tenant_id,
                'company_id' => $workOrder->company_id,
                'work_order_id' => $workOrder->id,
                'product_id' => $product->id,
                'warehouse_id' => $movement !== null ? $movement->warehouse_id : $warehouse?->id,
                'lot_number' => $itemNumber,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'metadata' => [
                    'item_number' => $itemNumber,
                    'description' => $description,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'stock_movement_id' => $movement?->id,
                ],
            ]);

            $workOrder->material_cost = ServiceAmounts::add($workOrder->material_cost, $totalCost);
            $workOrder->total_cost = ServiceAmounts::add($workOrder->total_cost, $totalCost);
            $workOrder->total_billable_amount = ServiceAmounts::add($workOrder->total_billable_amount, $totalPrice);
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

        if ($workOrder->customer_id === null) {
            throw ValidationException::withMessages(['customer_id' => __('work_orders.internal_not_invoiceable')]);
        }

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

    /**
     * Drafts the sales invoice of a completed work order through the invoicing service: one line per billable item and
     * per consumed part, at the prices recorded on the work order. The draft follows the normal invoice flow (approval,
     * posting, e-invoicing); the work order keeps the link and is closed to a second invoice.
     *
     * @throws ValidationException when the work order is not completed, not billable, internal, already invoiced or empty
     */
    public function invoiceWorkOrder(string $workOrderId, string $taxRate, ?string $dueDate = null, ?string $revenueAccountId = null, ?string $actorUserId = null): SalesInvoice
    {
        return DB::transaction(function () use ($workOrderId, $taxRate, $dueDate, $revenueAccountId, $actorUserId): SalesInvoice {
            $workOrder = WorkOrder::whereKey($workOrderId)->lockForUpdate()->firstOrFail();

            if ($workOrder->status !== 'completed') {
                throw ValidationException::withMessages(['work_order' => __('work_orders.billing_not_completed')]);
            }

            if (! $workOrder->is_billable) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.billing_not_billable')]);
            }

            if ($workOrder->is_invoiced || $workOrder->invoice_id !== null) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.billing_already_invoiced')]);
            }

            if (blank($workOrder->customer_id)) {
                throw ValidationException::withMessages(['customer_id' => __('work_orders.internal_not_invoiceable')]);
            }

            $lines = [];

            foreach (WorkOrderItem::where('work_order_id', $workOrder->id)->where('is_billable', true)->orderBy('created_at')->get() as $item) {
                $lines[] = ['description' => (string) $item->description, 'quantity' => (string) $item->quantity, 'unit_price' => (string) $item->unit_price];
            }

            foreach (PartsConsumption::where('work_order_id', $workOrder->id)->orderBy('created_at')->get() as $part) {
                $meta = (array) $part->getAttribute('metadata');
                $lines[] = [
                    'description' => (string) (Arr::get($meta, 'description') ?? $part->lot_number),
                    'quantity' => (string) $part->quantity,
                    'unit_price' => ServiceAmounts::money(Arr::get($meta, 'unit_price', '0')),
                    'product_id' => $part->product_id,
                ];
            }

            $lines = array_values(array_filter($lines, static fn (array $line): bool => Decimal::isPositive(Decimal::mul(ServiceAmounts::text($line['quantity']), ServiceAmounts::text($line['unit_price'])))));

            if ($lines === []) {
                throw ValidationException::withMessages(['work_order' => __('work_orders.billing_nothing_to_invoice')]);
            }

            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => (string) $workOrder->tenant_id,
                'contact_id' => (string) $workOrder->customer_id,
                'invoice_type' => 'standard',
                'date' => $this->clock->today()->toDateString(),
                'due_date' => $dueDate,
                'currency' => (string) ($workOrder->currency ?: 'EUR'),
                'notes' => "Work order {$workOrder->work_order_number}: {$workOrder->title}",
                'created_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
            ], array_map(static fn (array $line): array => [
                ...$line,
                'tax_rate' => $taxRate,
                'discount_percentage' => '0',
                'revenue_account_id' => $revenueAccountId,
            ], $lines));

            $workOrder->update(['is_invoiced' => true, 'invoice_id' => $invoice->id]);

            $this->auditLedger->record('WORK_ORDER_INVOICED', $workOrder, null, ['invoice_id' => $invoice->id], 'MODULE_17_ESM');

            return $invoice;
        });
    }
}
