<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\AssetManagement\Events\AssetAssignedEvent;
use Modules\AssetManagement\Events\AssetDisposedEvent;
use Modules\AssetManagement\Events\AssetTransferredEvent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\Timesheet;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

uses(DatabaseTransactions::class);

test('EAM AssetAssignedEvent, AssetTransferredEvent, and AssetDisposedEvent synchronize employee equipment_assigned JSONB', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $empA = Employee::factory()->active()->create(['tenant_id' => $tenant->id, 'equipment_assigned' => []]);
    $empB = Employee::factory()->active()->create(['tenant_id' => $tenant->id, 'equipment_assigned' => []]);
    $assetId = (string) Str::uuid7();

    // 1. Assign asset to Employee A
    event(new AssetAssignedEvent(
        assignmentId: (string) Str::uuid7(),
        assetId: $assetId,
        assigneeType: 'employee',
        assigneeId: (string) $empA->id,
        assigneeName: $empA->full_name,
        assignedAt: now()->toDateString(),
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id
    ));

    $equipA = $empA->fresh()->equipment_assigned;
    expect($equipA)->toBeArray()->toHaveKey($assetId)
        ->and($equipA[$assetId]['last_action'])->toBe('assigned');

    // 2. Transfer asset from Employee A to Employee B
    event(new AssetTransferredEvent(
        transferId: (string) Str::uuid7(),
        assetId: $assetId,
        fromLocationId: null,
        toLocationId: null,
        fromCustodianId: (string) $empA->id,
        toCustodianId: (string) $empB->id,
        fromProjectId: null,
        toProjectId: null,
        transferDate: now()->toDateString(),
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id
    ));

    expect($empA->fresh()->equipment_assigned[$assetId]['last_action'])->toBe('transferred_out')
        ->and($empB->fresh()->equipment_assigned[$assetId]['last_action'])->toBe('transferred_in');

    // 3. Dispose asset
    event(new AssetDisposedEvent(
        disposalId: (string) Str::uuid7(),
        assetId: $assetId,
        disposalType: 'scrap',
        assetCost: 1200.00,
        accumulatedDepreciation: 1100.00,
        carryingAmount: 100.00,
        proceedsAmount: 0.00,
        gainLossAmount: -100.00,
        disposalDate: now()->toDateString(),
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id
    ));

    expect($empB->fresh()->equipment_assigned[$assetId]['last_action'])->toBe('disposed');
});

test('WorkflowInstanceCompletedEvent and WorkflowInstanceRejectedEvent unlock and transition HR LeaveRequests and Timesheets', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $employee = Employee::factory()->active()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'fiscal_year' => 2026,
        'entitlement_hours' => 160.00,
        'accrued_hours' => 160.00,
        'pending_hours' => 16.00,
        'used_hours' => 0.00,
        'available_hours' => 144.00,
    ]);

    $wfInstanceApproveId = (string) Str::uuid7();
    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-11',
        'total_hours' => 16.00,
        'status' => 'pending_approval',
        'workflow_instance_id' => $wfInstanceApproveId,
        'is_locked' => true,
    ]);

    event(new WorkflowInstanceCompletedEvent(
        instanceId: $wfInstanceApproveId,
        status: 'approved',
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id
    ));

    expect($leaveRequest->fresh()->status)->toBe('approved')
        ->and($leaveRequest->fresh()->is_locked)->toBeFalse();

    $wfInstanceRejectId = (string) Str::uuid7();
    $timesheet = Timesheet::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'status' => 'submitted',
        'workflow_instance_id' => $wfInstanceRejectId,
        'is_locked' => true,
    ]);

    event(new WorkflowInstanceRejectedEvent(
        instanceId: $wfInstanceRejectId,
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id
    ));

    expect($timesheet->fresh()->status)->toBe('rejected')
        ->and($timesheet->fresh()->is_locked)->toBeFalse();
});

test('Projects ProjectClosureReadyEvent finalizes approved project timesheets to posted_to_project', function () {
    $tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($tenant);

    $employee = Employee::factory()->active()->create(['tenant_id' => $tenant->id]);
    $projectId = (string) Str::uuid7();

    $timesheet = Timesheet::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'project_id' => $projectId,
        'status' => 'approved',
    ]);

    event(new ProjectClosureReadyEvent(
        projectId: $projectId,
        finalContractValue: 120000.00,
        finalCost: 85000.00,
        unbilledWipBalance: 0.00,
        retainedEarningsImpact: 35000.00,
        fiscalYear: '2026',
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id
    ));

    expect($timesheet->fresh()->status)->toBe('posted_to_project');
});
