<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Modules\HumanResources\Contracts\EmployeeLifecycleServiceInterface;
use Modules\HumanResources\Contracts\OrganizationManagementServiceInterface;
use Modules\HumanResources\DTOs\HireEmployeeDTO;
use Modules\HumanResources\DTOs\PromoteEmployeeDTO;
use Modules\HumanResources\DTOs\TerminateEmployeeDTO;
use Modules\HumanResources\DTOs\TransferEmployeeDTO;
use Modules\HumanResources\Events\AccrualReadyEvent;
use Modules\HumanResources\Events\EmployeeCostForecastEvent;
use Modules\HumanResources\Events\EmployeeCostReadyEvent;
use Modules\HumanResources\Events\EmployeeHiredEvent;
use Modules\HumanResources\Events\EmployeePromotedEvent;
use Modules\HumanResources\Events\EmployeeTerminatedEvent;
use Modules\HumanResources\Events\EmployeeTransferredEvent;
use Modules\HumanResources\Events\PayrollForecastEvent;
use Modules\HumanResources\Models\BusinessUnit;
use Modules\HumanResources\Models\CostCenter;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\JobGrade;
use Modules\HumanResources\Models\Position;

uses(DatabaseTransactions::class);

test('hiring an employee creates active employment contract, lifecycle event, audit log, and dispatches cost and forecast events', function () {
    Event::fake([
        EmployeeHiredEvent::class,
        EmployeeCostReadyEvent::class,
        EmployeeCostForecastEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    $bu = BusinessUnit::factory()->create(['tenant_id' => $tenant->id]);
    $cc = CostCenter::factory()->create(['tenant_id' => $tenant->id, 'business_unit_id' => $bu->id]);
    $dept = Department::factory()->create([
        'tenant_id' => $tenant->id,
        'business_unit_id' => $bu->id,
        'cost_center_id' => $cc->id,
    ]);
    $grade = JobGrade::factory()->create(['tenant_id' => $tenant->id, 'level_rank' => 3]);
    $pos = Position::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $dept->id,
        'job_grade_id' => $grade->id,
    ]);

    $service = app(EmployeeLifecycleServiceInterface::class);
    $employee = $service->hireEmployee(new HireEmployeeDTO(
        tenantId: (string) $tenant->id,
        companyId: (string) $tenant->id,
        firstName: 'Leonardo',
        lastName: 'Bianchi',
        workEmail: 'leonardo.bianchi@enterprise.it',
        baseAnnualSalary: 62400.00,
        hireDate: '2026-03-01',
        employeeNumber: 'EMP-2026-8801',
        departmentId: (string) $dept->id,
        businessUnitId: (string) $bu->id,
        costCenterId: (string) $cc->id,
        positionId: (string) $pos->id,
        jobGradeId: (string) $grade->id,
        probationMonths: 3
    ));

    expect($employee->employment_status)->toBe('probation')
        ->and((float) $employee->base_annual_salary)->toBe(62400.00)
        ->and((float) $employee->hourly_cost_rate)->toBe(40.50) // (62400 / 2080) * 1.35 = 40.50
        ->and($employee->activeContract)->not->toBeNull()
        ->and($employee->lifecycleEvents()->where('event_type', 'hiring')->count())->toBe(1);

    Event::assertDispatched(EmployeeHiredEvent::class);
    Event::assertDispatched(EmployeeCostReadyEvent::class);
    Event::assertDispatched(EmployeeCostForecastEvent::class);
});

test('employee completes probation, gets promoted with duplicate promotion protection, transfers, terminates, and rehires', function () {
    Event::fake([
        EmployeePromotedEvent::class,
        EmployeeTransferredEvent::class,
        EmployeeTerminatedEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    $deptA = Department::factory()->create(['tenant_id' => $tenant->id]);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id]);
    $grade3 = JobGrade::factory()->create(['tenant_id' => $tenant->id, 'level_rank' => 3]);
    $grade4 = JobGrade::factory()->create(['tenant_id' => $tenant->id, 'level_rank' => 4]);
    $posSenior = Position::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id, 'job_grade_id' => $grade3->id]);
    $posLead = Position::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptA->id, 'job_grade_id' => $grade4->id]);

    $employee = Employee::factory()->probation()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $deptA->id,
        'position_id' => $posSenior->id,
        'job_grade_id' => $grade3->id,
        'base_annual_salary' => 55000.00,
    ]);

    $service = app(EmployeeLifecycleServiceInterface::class);

    // 1. Complete Probation
    $confirmed = $service->completeProbation($employee, '2026-06-01');
    expect($confirmed->employment_status)->toBe('active')
        ->and($confirmed->confirmation_date->toDateString())->toBe('2026-06-01');

    // 2. Promote Employee
    $promoDto = new PromoteEmployeeDTO(
        employeeId: (string) $employee->id,
        newPositionId: (string) $posLead->id,
        newJobGradeId: (string) $grade4->id,
        newBaseAnnualSalary: 72000.00,
        effectiveDate: '2026-07-01',
        reason: 'Promoted to Principal Lead'
    );
    $promoEvent = $service->promoteEmployee($promoDto);
    expect($promoEvent->event_type)->toBe('promotion')
        ->and((float) $employee->fresh()->base_annual_salary)->toBe(72000.00);

    // Duplicate promotion on same date to same salary/grade is blocked
    expect(fn () => $service->promoteEmployee($promoDto))
        ->toThrow(InvalidArgumentException::class);

    // 3. Transfer Employee
    $transferEvent = $service->transferEmployee(new TransferEmployeeDTO(
        employeeId: (string) $employee->id,
        toDepartmentId: (string) $deptB->id,
        effectiveDate: '2026-08-01',
        reason: 'Cross-functional transfer'
    ));
    expect($transferEvent->event_type)->toBe('transfer')
        ->and($employee->fresh()->department_id)->toBe($deptB->id);

    // 4. Terminate Employee
    $terminated = $service->terminateEmployee(new TerminateEmployeeDTO(
        employeeId: (string) $employee->id,
        terminationDate: '2026-10-31',
        reason: 'End of assignment',
        startOffboarding: false
    ));
    expect($terminated->employment_status)->toBe('terminated');

    // 5. Rehire Employee
    $rehired = $service->rehireEmployee($terminated, 78000.00, '2027-01-10');
    expect($rehired->employment_status)->toBe('rehired')
        ->and((float) $rehired->base_annual_salary)->toBe(78000.00);

    Event::assertDispatched(EmployeePromotedEvent::class);
    Event::assertDispatched(EmployeeTransferredEvent::class);
    Event::assertDispatched(EmployeeTerminatedEvent::class);
});

test('organization management service builds hierarchical org chart, assigns successor, and emits payroll forecast events', function () {
    Event::fake([
        AccrualReadyEvent::class,
        PayrollForecastEvent::class,
    ]);

    $tenant = Tenant::factory()->create();
    $parentDept = Department::factory()->create(['tenant_id' => $tenant->id, 'code' => 'DEP-EXEC', 'name' => 'Executive']);
    $childDept = Department::factory()->create([
        'tenant_id' => $tenant->id,
        'parent_id' => $parentDept->id,
        'code' => 'DEP-ENG',
        'name' => 'Engineering',
    ]);

    $employee = Employee::factory()->active()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $childDept->id,
        'base_annual_salary' => 60000.00,
    ]);

    $position = Position::factory()->create([
        'tenant_id' => $tenant->id,
        'department_id' => $parentDept->id,
        'is_critical_role' => true,
    ]);

    $orgService = app(OrganizationManagementServiceInterface::class);

    $tree = $orgService->buildOrgChart((string) $tenant->id);
    expect($tree)->toBeArray()->not->toBeEmpty()
        ->and($tree[0]['code'])->toBe('DEP-EXEC')
        ->and($tree[0]['children'])->toHaveCount(1);

    $updatedPos = $orgService->assignSuccessor($position, (string) $employee->id, 'ready_now');
    expect($updatedPos->successor_employee_id)->toBe($employee->id)
        ->and($updatedPos->readiness_level)->toBe('ready_now');

    $forecast = $orgService->generatePeriodCostAndPayrollForecast((string) $tenant->id, '2026-10');
    expect($forecast['active_headcount'])->toBe(1)
        ->and($forecast['gross_payroll'])->toBe(5000.00);

    Event::assertDispatched(AccrualReadyEvent::class);
    Event::assertDispatched(PayrollForecastEvent::class);
});
