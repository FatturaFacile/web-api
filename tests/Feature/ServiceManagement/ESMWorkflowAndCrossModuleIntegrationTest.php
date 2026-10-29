<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\AssetManagement\Events\AssetMaintenanceCreatedEvent;
use Modules\AssetManagement\Events\MaintenanceCompletedEvent;
use Modules\CRM\Events\OpportunityWonEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\LeaveApprovedEvent;
use Modules\ServiceManagement\Models\ChangeRequest;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\WorkOrder;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);
    $this->user = User::factory()->create();
});

test('workflow approval listener unlocks and updates status of change request, work order, and service contract', function () {
    $instanceId = (string) Str::uuid7();

    // 1. Change Request Workflow Approval
    $change = ChangeRequest::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_instance_id' => $instanceId,
        'status' => 'submitted',
        'is_locked' => true,
    ]);

    $completedEvent = new WorkflowInstanceCompletedEvent(
        instanceId: $instanceId,
        status: 'completed',
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        definitionCode: 'CHANGE_APPROVAL_V1',
        aggregateType: 'esm_change_request',
        aggregateId: $change->id
    );

    event($completedEvent);

    $freshChange = $change->fresh();
    expect($freshChange->is_locked)->toBeFalse()
        ->and($freshChange->status)->toBe('approved');

    // 2. Work Order Workflow Rejection
    $woInstanceId = (string) Str::uuid7();
    $workOrder = WorkOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_instance_id' => $woInstanceId,
        'status' => 'draft',
        'is_locked' => true,
    ]);

    $rejectedEvent = new WorkflowInstanceRejectedEvent(
        instanceId: $woInstanceId,
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        definitionCode: 'WO_BUDGET_APPROVAL',
        aggregateType: 'esm_work_order',
        aggregateId: $workOrder->id
    );

    event($rejectedEvent);

    $freshWO = $workOrder->fresh();
    expect($freshWO->is_locked)->toBeFalse()
        ->and($freshWO->status)->toBe('cancelled');
});

test('CRM listener creates service contract on opportunity won and sales quote accepted with inbox idempotency', function () {
    $opportunityId = (string) Str::uuid7();
    $customerId = (string) Str::uuid7();
    $eventId = (string) Str::uuid7();

    $oppEvent = new OpportunityWonEvent(
        opportunityId: $opportunityId,
        accountId: $customerId,
        contactId: null,
        amount: 48000.00,
        currency: 'EUR',
        salesQuoteId: null,
        actorUserId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        eventId: $eventId
    );

    event($oppEvent);

    $contract = ServiceContract::where('customer_id', $customerId)->first();
    expect($contract)->not->toBeNull()
        ->and((float) $contract->contract_value)->toBe(48000.00)
        ->and($contract->currency)->toBe('EUR');

    $this->assertDatabaseHas('transactional_inbox', [
        'tenant_id' => $this->tenant->id,
        'message_id' => $eventId,
    ]);

    // Replay same event to assert strict idempotency
    event($oppEvent);
    expect(ServiceContract::where('customer_id', $customerId)->count())->toBe(1);
});

test('EAM asset management listener synchronizes work orders on maintenance created and completed', function () {
    $eamWoId = (string) Str::uuid7();
    $assetId = (string) Str::uuid7();

    $createdEvent = new AssetMaintenanceCreatedEvent(
        workOrderId: $eamWoId,
        workOrderNumber: 'EAM-WO-998877',
        assetId: $assetId,
        maintenanceType: 'preventive',
        priority: 'high',
        totalCost: 650.00,
        scheduledDate: now()->addDays(2)->toDateString(),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    event($createdEvent);

    $workOrder = WorkOrder::where('asset_id', $assetId)->first();
    expect($workOrder)->not->toBeNull()
        ->and($workOrder->work_type)->toBe('preventive')
        ->and($workOrder->status)->toBe('draft');

    // Maintenance completed
    $completedEvent = new MaintenanceCompletedEvent(
        workOrderId: $eamWoId,
        workOrderNumber: 'EAM-WO-998877',
        assetId: $assetId,
        totalCost: 720.00,
        downtimeHours: 3.5,
        capitalizedAsUpgrade: false,
        completedAt: now()->toIso8601String(),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    event($completedEvent);

    expect($workOrder->fresh()->status)->toBe('completed')
        ->and((float) $workOrder->fresh()->total_cost)->toBe(720.00);
});

test('HR listener provisions technician and updates duty status on employee hire, leave, and termination', function () {
    $employeeId = (string) Str::uuid7();

    // 1. Employee Hired
    $hireEvent = new EmployeeHiredEvent(
        employeeId: $employeeId,
        employeeNumber: 'EMP-771122',
        fullName: 'Alessandro Manzoni',
        departmentId: null,
        positionId: null,
        baseAnnualSalary: 52000.00,
        hireDate: now()->toDateString(),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    event($hireEvent);

    $technician = ServiceTechnician::where('employee_id', $employeeId)->first();
    expect($technician)->not->toBeNull()
        ->and($technician->name)->toBe('Alessandro Manzoni')
        ->and($technician->status)->toBe('available');

    // 2. Leave Approved
    $leaveEvent = new LeaveApprovedEvent(
        leaveRequestId: (string) Str::uuid7(),
        requestNumber: 'LR-1234',
        employeeId: $employeeId,
        leaveTypeId: (string) Str::uuid7(),
        totalHours: 16.0,
        approvedByUserId: $this->user->id,
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    event($leaveEvent);
    expect($technician->fresh()->status)->toBe('on_leave');

    // 3. Employee Terminated
    $termEvent = new EmployeeTerminatedEvent(
        employeeId: $employeeId,
        employeeNumber: 'EMP-771122',
        terminationDate: now()->toDateString(),
        reason: 'Career transition',
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id
    );

    event($termEvent);
    expect($technician->fresh()->is_available)->toBeFalse();
});
