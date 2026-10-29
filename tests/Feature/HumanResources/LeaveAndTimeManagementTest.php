<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\DTOs\RecordTimesheetDTO;
use Modules\HumanResources\DTOs\SubmitLeaveRequestDTO;
use Modules\HumanResources\Events\LaborCostReadyEvent;
use Modules\HumanResources\Events\LeaveApprovedEvent;
use Modules\HumanResources\Events\LeaveLiabilityReadyEvent;
use Modules\HumanResources\Events\LeaveRequestedEvent;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveType;

uses(DatabaseTransactions::class);

test('leave balance initialization, monthly accrual with SKIP LOCKED, leave request submission, overlap protection, and approval work end-to-end', function () {
    Event::fake([
        LeaveLiabilityReadyEvent::class,
        LeaveRequestedEvent::class,
        LeaveApprovedEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'hourly_cost_rate' => 35.00,
    ]);
    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'annual_entitlement_hours' => 120.00,
        'accrual_frequency' => 'monthly',
    ]);

    $service = app(LeaveAndTimeServiceInterface::class);

    // 1. Initialize Balance
    $balance = $service->initializeLeaveBalance((string) $tenant->id, (string) $employee->id, (string) $leaveType->id, 2026, 80.00, 16.00);
    expect((float) $balance->available_hours)->toBe(96.00);

    // 2. Run Monthly Accruals (adds 120/12 = 10.00 hours)
    $accruedCount = $service->runMonthlyAccruals((string) $tenant->id, 2026);
    expect($accruedCount)->toBe(1)
        ->and((float) $balance->fresh()->available_hours)->toBe(106.00);

    Event::assertDispatched(LeaveLiabilityReadyEvent::class);

    // 3. Submit Leave Request
    $request = $service->submitLeaveRequest(new SubmitLeaveRequestDTO(
        tenantId: (string) $tenant->id,
        employeeId: (string) $employee->id,
        leaveTypeId: (string) $leaveType->id,
        startDate: '2026-08-10',
        endDate: '2026-08-14',
        totalHours: 40.00,
        reason: 'Summer Vacation'
    ));

    expect($request->status)->toBe('pending_approval')
        ->and((float) $balance->fresh()->pending_hours)->toBe(40.00)
        ->and((float) $balance->fresh()->available_hours)->toBe(66.00);

    // Overlapping leave request must be rejected
    expect(fn () => $service->submitLeaveRequest(new SubmitLeaveRequestDTO(
        tenantId: (string) $tenant->id,
        employeeId: (string) $employee->id,
        leaveTypeId: (string) $leaveType->id,
        startDate: '2026-08-12',
        endDate: '2026-08-15',
        totalHours: 24.00
    )))->toThrow(InvalidArgumentException::class);

    // 4. Approve Leave Request
    $approved = $service->approveLeaveRequest($request);
    expect($approved->status)->toBe('approved')
        ->and((float) $balance->fresh()->used_hours)->toBe(40.00)
        ->and((float) $balance->fresh()->pending_hours)->toBe(0.00);

    Event::assertDispatched(LeaveRequestedEvent::class);
    Event::assertDispatched(LeaveApprovedEvent::class);
});

test('attendance recording and project timesheet approval calculate labor cost with overtime multiplier and emit LaborCostReadyEvent', function () {
    Event::fake([
        LaborCostReadyEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'hourly_cost_rate' => 40.00,
    ]);

    $service = app(LeaveAndTimeServiceInterface::class);

    $attendance = $service->recordAttendance((string) $tenant->id, (string) $employee->id, '2026-10-15', 8.0, 2.0);
    expect((float) $attendance->regular_hours)->toBe(8.00)
        ->and((float) $attendance->overtime_hours)->toBe(2.00);

    // Submit Timesheet: 8 regular hours * 40 = 320 + 2 overtime hours * 40 * 1.25 = 100 => 420.00
    $timesheet = $service->submitTimesheet(new RecordTimesheetDTO(
        tenantId: (string) $tenant->id,
        employeeId: (string) $employee->id,
        workDate: '2026-10-15',
        regularHours: 8.0,
        overtimeHours: 2.0,
        billableHours: 10.0,
        description: 'CNC Spindle Calibration'
    ));

    expect((float) $timesheet->total_hours)->toBe(10.00)
        ->and((float) $timesheet->total_labor_cost)->toBe(420.00);

    $approved = $service->approveTimesheet($timesheet);
    expect($approved->status)->toBe('approved');

    Event::assertDispatched(LaborCostReadyEvent::class, function (LaborCostReadyEvent $event) {
        return $event->totalLaborCost === 420.00 && $event->totalHours === 10.00;
    });
});
