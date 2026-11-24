<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\ExitInterview;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\Position;

uses(RefreshDatabase::class);

function hranMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt(Str::random(24)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function hranApi(?User $user = null, ?Tenant $tenant = null): mixed
{
    Sanctum::actingAs($user ?? test()->manager);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

/**
 * @param  array<string, mixed>  $values
 */
function hranEmployee(array $values): Employee
{
    return Employee::factory()->create([
        'tenant_id' => test()->tenant->id,
        'position_id' => null,
        'job_grade_id' => null,
        'equipment_assigned' => [],
        ...$values,
    ]);
}

/**
 * @return array<string, int|float|null|string>
 */
function hranFind(array $rows, string $key, string $value): array
{
    return collect($rows)->firstWhere($key, $value) ?? [];
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Analisi SA', 'legal_name' => 'Analisi SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = hranMember($this->tenant);
    $this->sales = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Sales']);
    $this->ops = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Operations']);

    $this->veteran = hranEmployee(['department_id' => $this->sales->id, 'hire_date' => '2020-01-15', 'probation_end_date' => '2020-04-15', 'confirmation_date' => '2020-04-15']);
    $this->fixedTerm = hranEmployee(['department_id' => $this->sales->id, 'hire_date' => '2024-03-01', 'probation_end_date' => '2024-06-01', 'confirmation_date' => '2024-06-01']);
    $this->newcomer = hranEmployee(['department_id' => $this->ops->id, 'employment_status' => 'probation', 'hire_date' => '2026-02-01', 'probation_end_date' => '2026-11-20', 'confirmation_date' => null]);
    $this->leaver = hranEmployee(['department_id' => $this->ops->id, 'employment_status' => 'terminated', 'hire_date' => '2022-05-01', 'termination_date' => '2026-06-30']);
    hranEmployee(['department_id' => $this->ops->id, 'employment_status' => 'candidate', 'hire_date' => '2026-12-01', 'probation_end_date' => null, 'confirmation_date' => null]);

    EmploymentContract::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->veteran->id, 'contract_type' => 'permanent', 'end_date' => null]);
    EmploymentContract::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->fixedTerm->id, 'contract_type' => 'fixed_term', 'end_date' => '2026-12-01']);
});

test('the headcount is split by department and contract type and followed month by month', function (): void {
    $data = hranApi()->getJson('/api/v1/hr/analytics/headcount?from=2026-05-01&to=2026-08-31')->assertOk()->json('data');

    expect($data['as_of'])->toBe('2026-11-06')
        ->and($data['total'])->toBe(3)
        ->and(hranFind($data['by_department'], 'department_name', 'Sales')['headcount'])->toBe(2)
        ->and(hranFind($data['by_department'], 'department_name', 'Operations')['headcount'])->toBe(1)
        ->and(hranFind($data['by_contract_type'], 'contract_type', 'fixed_term')['headcount'])->toBe(1)
        ->and(hranFind($data['by_contract_type'], 'contract_type', 'unknown')['headcount'])->toBe(1)
        ->and(hranFind($data['by_employment_type'], 'employment_type', 'full_time')['headcount'])->toBe(3)
        ->and(array_column($data['series'], 'headcount'))->toBe([4, 4, 3, 3])
        ->and(array_column($data['series'], 'period'))->toBe(['2026-05', '2026-06', '2026-07', '2026-08']);

    $past = hranApi()->getJson('/api/v1/hr/analytics/headcount?as_of=2026-06-30&from=2026-06-01&to=2026-06-30')->assertOk()->json('data');
    expect($past['total'])->toBe(4)->and($past['series'])->toHaveCount(1);
});

test('the turnover counts hires and terminations per period and groups the exit reasons', function (): void {
    ExitInterview::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->leaver->id, 'reason_code' => 'resignation_better_offer']);

    $data = hranApi()->getJson('/api/v1/hr/analytics/turnover?from=2026-01-01&to=2026-12-31&granularity=quarter')->assertOk()->json('data');

    expect(array_column($data['periods'], 'period'))->toBe(['2026-Q1', '2026-Q2', '2026-Q3', '2026-Q4'])
        ->and($data['periods'][0]['hires'])->toBe(1)
        ->and($data['periods'][1]['terminations'])->toBe(1)
        ->and($data['periods'][1]['turnover_rate'])->toEqual(25.0)
        ->and($data['totals']['hires'])->toBe(1)
        ->and($data['totals']['terminations'])->toBe(1)
        ->and($data['totals']['opening_headcount'])->toBe(3)
        ->and($data['totals']['closing_headcount'])->toBe(3)
        ->and($data['totals']['turnover_rate'])->toEqual(33.33)
        ->and($data['by_reason'])->toBe([['reason_code' => 'resignation_better_offer', 'count' => 1]])
        ->and($data['leavers_without_exit_interview'])->toBe(0);

    $monthly = hranApi()->getJson('/api/v1/hr/analytics/turnover?from=2026-05-15&to=2026-07-10')->assertOk()->json('data');
    expect(array_column($monthly['periods'], 'period'))->toBe(['2026-05', '2026-06', '2026-07'])
        ->and($monthly['periods'][0]['start'])->toBe('2026-05-15')
        ->and($monthly['periods'][2]['end'])->toBe('2026-07-10')
        ->and($monthly['leavers_without_exit_interview'])->toBe(0);

    $yearly = hranApi()->getJson('/api/v1/hr/analytics/turnover?from=2025-01-01&to=2026-12-31&granularity=year')->assertOk()->json('data');
    expect(array_column($yearly['periods'], 'period'))->toBe(['2025', '2026']);
});

test('the average tenure covers the present employees and the leavers', function (): void {
    $data = hranApi()->getJson('/api/v1/hr/analytics/tenure')->assertOk()->json('data');

    expect($data['employees'])->toBe(3)
        ->and($data['average_tenure_years'])->toBeGreaterThan(3.3)->toBeLessThan(3.5)
        ->and($data['average_tenure_leavers_years'])->toBeGreaterThan(4.1)->toBeLessThan(4.2)
        ->and(hranFind($data['distribution'], 'bucket', 'lt_1')['employees'])->toBe(1)
        ->and(hranFind($data['distribution'], 'bucket', 'from_1_to_3')['employees'])->toBe(1)
        ->and(hranFind($data['distribution'], 'bucket', 'from_5_to_10')['employees'])->toBe(1)
        ->and(hranFind($data['by_department'], 'department_name', 'Operations')['average_tenure_years'])->toBeLessThan(1);

    $empty = hranApi()->getJson('/api/v1/hr/analytics/tenure?as_of=2019-01-01')->assertOk()->json('data');
    expect($empty['employees'])->toBe(0)->and($empty['average_tenure_years'])->toBeNull();
});

test('the absenteeism rate leaves planned leave out and clips the leave to the range', function (): void {
    $sick = LeaveType::factory()->create(['tenant_id' => $this->tenant->id, 'category' => 'sick']);
    $vacation = LeaveType::factory()->create(['tenant_id' => $this->tenant->id, 'category' => 'vacation']);
    $leave = static fn (Employee $employee, LeaveType $type, string $from, string $to, float $days, string $status = 'approved'): LeaveRequest => LeaveRequest::factory()->create([
        'tenant_id' => test()->tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $from, 'end_date' => $to, 'total_days' => $days, 'status' => $status,
    ]);
    $leave($this->veteran, $sick, '2026-11-02', '2026-11-03', 2);
    $leave($this->fixedTerm, $vacation, '2026-11-04', '2026-11-06', 3);
    $leave($this->newcomer, $sick, '2026-10-30', '2026-11-03', 3);
    $leave($this->veteran, $sick, '2026-11-05', '2026-11-05', 1, 'pending_approval');
    $leave($this->veteran, $sick, '2026-09-01', '2026-09-02', 2);

    $data = hranApi()->getJson('/api/v1/hr/analytics/absenteeism?from=2026-11-02&to=2026-11-06')->assertOk()->json('data');

    expect($data['scheduled_days'])->toBe(15)
        ->and($data['absence_days'])->toEqual(3.2)
        ->and($data['planned_leave_days'])->toEqual(3.0)
        ->and($data['absenteeism_rate'])->toEqual(21.33)
        ->and($data['total_leave_rate'])->toEqual(41.33)
        ->and(hranFind($data['by_category'], 'category', 'sick')['days'])->toEqual(3.2)
        ->and(hranFind($data['by_category'], 'category', 'vacation')['planned'])->toBeTrue()
        ->and(hranFind($data['by_department'], 'department_name', 'Sales')['absence_days'])->toEqual(2.0)
        ->and(hranFind($data['by_department'], 'department_name', 'Operations')['absenteeism_rate'])->toEqual(24.0);

    $none = hranApi()->getJson('/api/v1/hr/analytics/absenteeism?from=2019-01-01&to=2019-01-31')->assertOk()->json('data');
    expect($none['scheduled_days'])->toBe(0)->and($none['absenteeism_rate'])->toBeNull();
});

test('open positions compare the budget with the employees filling them', function (): void {
    $position = Position::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $this->sales->id, 'headcount_budget' => 3]);
    Position::factory()->create(['tenant_id' => $this->tenant->id, 'department_id' => $this->ops->id, 'headcount_budget' => 2, 'is_active' => false]);
    DB::table('hr_employees')->whereIn('id', [$this->veteran->id, $this->fixedTerm->id, $this->leaver->id])->update(['position_id' => $position->id]);
    JobPosting::factory()->create(['tenant_id' => $this->tenant->id, 'position_id' => $position->id, 'department_id' => $this->sales->id]);
    JobPosting::factory()->draft()->create(['tenant_id' => $this->tenant->id, 'position_id' => $position->id, 'department_id' => $this->sales->id]);

    $data = hranApi()->getJson('/api/v1/hr/analytics/positions')->assertOk()->json('data');

    expect($data['budget'])->toBe(3)->and($data['filled'])->toBe(2)->and($data['open'])->toBe(1)->and($data['open_job_postings'])->toBe(1)
        ->and($data['positions'])->toHaveCount(1)
        ->and($data['positions'][0]['open_postings'])->toBe(1);
});

test('the upcoming probation ends and contract expiries are listed with their days left', function (): void {
    $data = hranApi()->getJson('/api/v1/hr/analytics/upcoming-expiries')->assertOk()->json('data');

    expect($data['days'])->toBe(90)
        ->and($data['probation_ends'])->toHaveCount(1)
        ->and($data['probation_ends'][0]['employee_id'])->toBe($this->newcomer->id)
        ->and($data['probation_ends'][0]['days_remaining'])->toBe(14)
        ->and($data['contract_expiries'])->toHaveCount(1)
        ->and($data['contract_expiries'][0]['full_name'])->toBe($this->fixedTerm->full_name)
        ->and($data['contract_expiries'][0]['days_remaining'])->toBe(25);

    $short = hranApi()->getJson('/api/v1/hr/analytics/upcoming-expiries?days=10')->assertOk()->json('data');
    expect($short['probation_ends'])->toBe([])->and($short['contract_expiries'])->toBe([]);

    $newcomer = Employee::query()->findOrFail($this->newcomer->id);
    $newcomer->update(['confirmation_date' => '2026-11-06']);
    expect(hranApi()->getJson('/api/v1/hr/analytics/upcoming-expiries')->json('data.probation_ends'))->toBe([]);
});

test('the analytics payloads are validated', function (): void {
    hranApi()->getJson('/api/v1/hr/analytics/headcount?from=2026-05-01&to=2026-04-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    hranApi()->getJson('/api/v1/hr/analytics/headcount?as_of=06/11/2026')->assertUnprocessable()->assertJsonValidationErrors('as_of');
    hranApi()->getJson('/api/v1/hr/analytics/turnover?granularity=week')->assertUnprocessable()->assertJsonValidationErrors('granularity');
    hranApi()->getJson('/api/v1/hr/analytics/upcoming-expiries?days=0')->assertUnprocessable()->assertJsonValidationErrors('days');
    hranApi()->getJson('/api/v1/hr/analytics/upcoming-expiries?days=400')->assertUnprocessable()->assertJsonValidationErrors('days');
    hranApi()->getJson('/api/v1/hr/analytics/turnover?from=1900-01-01&to=2026-11-06')->assertUnprocessable();
});

test('the analytics are isolated between companies and need the HR module role', function (): void {
    $otherManager = hranMember($this->other);

    expect(hranApi($otherManager, $this->other)->getJson('/api/v1/hr/analytics/headcount')->assertOk()->json('data.total'))->toBe(0)
        ->and(hranApi($otherManager, $this->other)->getJson('/api/v1/hr/analytics/turnover')->assertOk()->json('data.totals.terminations'))->toBe(0)
        ->and(hranApi($otherManager, $this->other)->getJson('/api/v1/hr/analytics/tenure')->assertOk()->json('data.employees'))->toBe(0)
        ->and(hranApi($otherManager, $this->other)->getJson('/api/v1/hr/analytics/absenteeism')->assertOk()->json('data.scheduled_days'))->toBe(0)
        ->and(hranApi($otherManager, $this->other)->getJson('/api/v1/hr/analytics/positions')->assertOk()->json('data.positions'))->toBe([])
        ->and(hranApi($otherManager, $this->other)->getJson('/api/v1/hr/analytics/upcoming-expiries')->assertOk()->json('data.contract_expiries'))->toBe([]);

    $member = hranMember($this->tenant, 'member');
    foreach (['headcount', 'turnover', 'tenure', 'absenteeism', 'positions', 'upcoming-expiries'] as $report) {
        hranApi($member)->getJson("/api/v1/hr/analytics/{$report}")->assertForbidden();
    }
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/hr/analytics/headcount')->assertUnauthorized();
});
