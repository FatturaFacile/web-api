<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\ServiceManagement\Actions\DispatchWorkOrderAction;
use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\DTOs\CreateWorkOrderDTO;
use Modules\ServiceManagement\DTOs\RecordVisitDTO;
use Modules\ServiceManagement\DTOs\ScheduleAppointmentDTO;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
    $this->workOrderService = app(WorkOrderAndFieldServiceInterface::class);
    $this->dispatchAction = app(DispatchWorkOrderAction::class);
});

test('work order lifecycle handles items, dispatch, appointment scheduling, on-site visit, parts, completion, and invoicing', function () {
    $technician = ServiceTechnician::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'hourly_cost_rate' => 40.00,
        'hourly_billable_rate' => 95.00,
    ]);

    // 1. Create Work Order with labor and material items
    $dto = new CreateWorkOrderDTO(
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        title: 'Emergency Generator Overhaul',
        description: 'Overhaul cooling pump and replace alternator brushes',
        workType: 'corrective',
        priority: 'high',
        locationAddress: 'Via Nazionale 120, Roma',
        items: [
            [
                'item_type' => 'labor',
                'description' => 'Diagnostic and pump repair',
                'quantity' => 3.0,
                'unit_cost' => 40.00,
                'unit_price' => 95.00,
                'is_billable' => true,
            ],
            [
                'item_type' => 'part',
                'description' => 'Coolant gasket kit',
                'quantity' => 1.0,
                'unit_cost' => 60.00,
                'unit_price' => 120.00,
                'is_billable' => true,
            ],
        ]
    );

    $workOrder = $this->workOrderService->createWorkOrder($dto, $this->user->id);

    expect($workOrder)->toBeInstanceOf(WorkOrder::class)
        ->and($workOrder->work_order_number)->toStartWith('WO-')
        ->and($workOrder->status)->toBe('draft')
        ->and((float) $workOrder->total_cost)->toBe(180.00)
        ->and((float) $workOrder->total_billable_amount)->toBe(405.00);

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.work_order.created',
        'aggregate_id' => $workOrder->id,
    ]);

    // 2. Dispatch
    $dispatched = $this->dispatchAction->execute($workOrder->id, $technician->id, $this->user->id);
    expect($dispatched->status)->toBe('dispatched')
        ->and($dispatched->assigned_technician_id)->toBe($technician->id);

    // 3. Schedule Appointment
    $scheduleDto = new ScheduleAppointmentDTO(
        workOrderId: $workOrder->id,
        technicianId: $technician->id,
        scheduledStart: now()->addHours(2)->toIso8601String(),
        scheduledEnd: now()->addHours(6)->toIso8601String(),
        notes: 'Customer gate security badge required'
    );
    $appointment = $this->workOrderService->scheduleAppointment($scheduleDto, $this->user->id);
    expect($appointment->status)->toBe('scheduled')
        ->and($workOrder->fresh()->status)->toBe('scheduled');

    // 4. Record Visit
    $visitDto = new RecordVisitDTO(
        workOrderId: $workOrder->id,
        serviceAppointmentId: $appointment->id,
        technicianId: $technician->id,
        checkInAt: now()->subHours(2)->toIso8601String(),
        checkOutAt: now()->toIso8601String(),
        workSummary: 'Technician arrived on site, replaced worn gasket, and verified operating pressure.'
    );
    $visit = $this->workOrderService->recordVisit($visitDto, $this->user->id);
    expect($visit->visit_number)->toStartWith('VIS-')
        ->and($workOrder->fresh()->status)->toBe('in_progress');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.service_visit.completed',
        'aggregate_id' => $visit->id,
    ]);

    // 5. Consume Additional Part
    $consumption = $this->workOrderService->consumePart(
        workOrderId: $workOrder->id,
        itemNumber: 'BRUSH-ALT-88',
        description: 'Heavy duty alternator carbon brushes',
        quantity: 2.0,
        unitCost: 15.00,
        unitPrice: 35.00,
        warehouseId: null,
        actorUserId: $this->user->id
    );
    expect((float) $consumption->total_cost)->toBe(30.00);

    $freshWO = $workOrder->fresh();
    expect((float) $freshWO->total_cost)->toBe(210.00)
        ->and((float) $freshWO->total_billable_amount)->toBe(475.00);

    // 6. Complete Work Order with Customer Sign-off
    $completeDto = new CompleteWorkOrderDTO(
        workOrderId: $workOrder->id,
        completionNotes: 'All checks passed within manufacturer tolerances.',
        resolutionSummary: 'Generator returned to primary operational standby.',
        customerSignatureUrl: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0...',
        signedByCustomerName: 'Giancarlo Brambilla',
        signedAt: now()->toIso8601String()
    );
    $completed = $this->workOrderService->completeWorkOrder($completeDto, $this->user->id);

    expect($completed->status)->toBe('completed')
        ->and($completed->customer_signed)->toBeTrue()
        ->and($completed->signed_by_name)->toBe('Giancarlo Brambilla');

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.work_order.completed',
        'aggregate_id' => $workOrder->id,
    ]);

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'accounting.esm.service_cost_ready',
        'aggregate_id' => $workOrder->id,
    ]);

    // 7. Mark Invoice Ready
    $this->workOrderService->markInvoiceReady($workOrder->id, $this->user->id);
    expect($workOrder->fresh()->is_invoice_ready)->toBeTrue();

    $this->assertDatabaseHas('transactional_outbox', [
        'tenant_id' => $this->tenant->id,
        'event_type' => 'esm.service_invoice.ready',
        'aggregate_id' => $workOrder->id,
    ]);
});
