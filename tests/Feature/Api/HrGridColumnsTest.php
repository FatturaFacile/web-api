<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Http\GridColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\HumanResources\Models\Certification;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\LeaveAccrualPolicy;
use Modules\HumanResources\Models\LeaveBalance;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OvertimeRule;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\HumanResources\Models\Position;
use Modules\HumanResources\Models\ShiftSequence;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeMealBooking;
use Modules\HumanResources\Models\TimePeriodClosing;
use Modules\HumanResources\Models\TimeScheduleGroup;
use Modules\HumanResources\Models\Timesheet;
use Modules\HumanResources\Models\TrainingCourse;
use Modules\HumanResources\Models\TrainingRecord;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\PayrollAbsence;
use Modules\Payroll\Models\PayrollAnnualParameter;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollLegalParameter;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\SalaryCertificate;
use Modules\Payroll\Models\ShortTimeCase;
use Modules\Payroll\Models\ShortTimeClaim;

uses(RefreshDatabase::class);

/**
 * @param  Builder<*>  $query
 * @param  array<string, mixed>  $parameters
 */
function hrGridPage(Builder $query, array $parameters, int $perPage = 10): LengthAwarePaginator
{
    app()->instance('request', Request::create('/grid', 'GET', $parameters));

    return $query->gridPaginate($perPage);
}

/**
 * Every column of the HR and payroll grids (the keys the frontend sends as sort_by, filter[...] and grid_values):
 * a key the server does not know is silently ignored, which would make the column header do nothing.
 *
 * @return array<string, array{0: class-string, 1: list<string>}>
 */
dataset('hr grid columns', [
    'employment contracts' => [EmploymentContract::class, ['contract_number', 'contract_type', 'job_title', 'start_date', 'end_date', 'weekly_hours', 'base_annual_salary', 'currency', 'status']],
    'leave types' => [LeaveType::class, ['code', 'name', 'category', 'is_paid', 'requires_approval', 'annual_entitlement_hours', 'accrual_frequency', 'is_active']],
    'leave accrual policies' => [LeaveAccrualPolicy::class, ['code', 'name', 'leave_type_id', 'employment_type', 'annual_entitlement_hours', 'accrual_frequency', 'prorate_by_fte', 'valid_from', 'valid_to', 'is_active']],
    'departments' => [Department::class, ['code', 'name', 'parent_id', 'is_active']],
    'positions' => [Position::class, ['code', 'title', 'department_id', 'headcount_budget', 'is_critical_role', 'successor_employee_id', 'readiness_level', 'is_active']],
    'job postings' => [JobPosting::class, ['title', 'location', 'employment_type', 'status', 'opens_on', 'closes_on']],
    'job applications' => [JobApplication::class, ['job_posting_id', 'status', 'retention_until']],
    'shift types' => [ShiftType::class, ['code', 'name', 'category', 'start_time', 'end_time', 'is_active']],
    'shift sequences' => [ShiftSequence::class, ['name', 'pattern', 'skip_holidays', 'is_active']],
    'performance reviews' => [PerformanceReview::class, ['review_number', 'employee_id', 'review_period', 'review_type', 'goals_score', 'competencies_score', 'overall_rating', 'promotion_recommended', 'successor_readiness', 'status']],
    'training courses' => [TrainingCourse::class, ['course_code', 'title', 'category', 'provider', 'duration_hours', 'cost_per_participant', 'validity_months', 'is_mandatory_compliance']],
    'training records' => [TrainingRecord::class, ['enrollment_number', 'employee_id', 'course_id', 'status', 'assigned_date', 'score']],
    'certifications' => [Certification::class, ['code', 'name', 'issuing_authority', 'issue_date', 'expiry_date', 'is_mandatory', 'status']],
    'schedule groups' => [TimeScheduleGroup::class, ['name', 'is_active']],
    'overtime rules' => [OvertimeRule::class, ['code', 'name', 'basis', 'threshold_minutes', 'premium_percent', 'wage_type_code', 'priority', 'valid_from', 'valid_to', 'is_active']],
    'time clock events' => [TimeClockEvent::class, ['user_id', 'event_type', 'occurred_at', 'source', 'note']],
    'time audit' => [TimeAuditEntry::class, ['occurred_at', 'actor_name', 'action', 'entity_type', 'reason']],
    'employees' => [Employee::class, ['employee_number', 'full_name', 'work_email', 'employment_type', 'employment_status', 'base_annual_salary', 'hire_date']],
    'leave requests' => [LeaveRequest::class, ['request_number', 'employee.full_name', 'leaveType.name', 'start_date', 'end_date', 'total_hours', 'status']],
    'timesheets' => [Timesheet::class, ['timesheet_number', 'employee.full_name', 'work_date', 'regular_hours', 'overtime_hours', 'total_hours', 'billable_hours', 'status']],
    'leave balances' => [LeaveBalance::class, ['employee.full_name', 'fiscal_year', 'entitlement_hours', 'carryover_hours', 'accrued_hours', 'used_hours', 'pending_hours', 'available_hours']],
    'time closings' => [TimePeriodClosing::class, ['year', 'month', 'status', 'closed_at', 'reopen_count', 'reopen_reason']],
    'meal bookings' => [TimeMealBooking::class, ['user_id', 'meal_type', 'occurred_at', 'source']],
    'employee documents' => [EmployeeDocument::class, ['title', 'document_type', 'expiry_date', 'retention_until']],
    'onboarding checklists' => [OnboardingChecklist::class, ['employee_id']],
    'collective agreements' => [CollectiveAgreement::class, ['code', 'name', 'weekly_hours', 'installments', 'fourteenth_month', 'employer_inps_rate_percent', 'is_generic']],
    'wage types' => [PayrollWageType::class, ['code', 'name', 'kind', 'certificate_box', 'is_active']],
    'family allowance rates' => [PayrollFamilyAllowanceRate::class, ['canton', 'valid_from', 'child_allowance', 'education_allowance']],
    'payslips' => [Payslip::class, ['year', 'month', 'employee_name', 'gross_salary', 'employee_deductions', 'net_salary']],
    'salary certificates' => [SalaryCertificate::class, ['year', 'employee_id', 'box8_gross', 'box10_1_ordinary_pension', 'box10_2_pension_buyin', 'box11_net', 'box12_withholding_tax', 'box13_1_effective_expenses', 'box13_2_lump_sum_expenses', 'box15_remarks', 'generated_at']],
    'payroll inputs' => [PayrollInput::class, ['employee_id', 'year', 'month', 'wage_type_code', 'quantity', 'rate', 'amount', 'note']],
    'payroll absences' => [PayrollAbsence::class, ['employee_id', 'absence_type', 'start_date', 'end_date', 'percent']],
    'payroll children' => [PayrollEmployeeChild::class, ['employee_id', 'date_of_birth', 'in_education', 'allowance_eligible']],
    'payroll profiles' => [PayrollEmployeeProfile::class, ['employee_id', 'pay_type', 'weekly_hours', 'work_canton', 'swiss_permit_type', 'is_withholding_tax_subject', 'collective_agreement_code', 'contract_level']],
    'legal parameters' => [PayrollLegalParameter::class, ['topic', 'year', 'source']],
    'annual parameters' => [PayrollAnnualParameter::class, ['country', 'year', 'legal_reference']],
    'short-time cases' => [ShortTimeCase::class, ['kind', 'starts_on', 'ends_on', 'authority_reference', 'claims_count']],
    'payroll runs' => [PayrollRun::class, ['year', 'month', 'country', 'status', 'payment_date', 'reopen_count', 'employees_count', 'total_gross', 'total_net', 'created_at']],
]);

test('every column of an HR grid is known by the server', function (string $model, array $keys): void {
    $columns = new GridColumns($model::withoutGlobalScopes());

    expect($columns->isUsable())->toBeTrue();
    foreach ($keys as $key) {
        expect($columns->kind($key))->not->toBeNull("{$model} does not know the grid column {$key}");
    }
})->with('hr grid columns');

test('the is_generic column separates the shared rows from the ones of the company', function (): void {
    $tenant = Tenant::factory()->create();
    CollectiveAgreement::withoutGlobalScopes()->create(['tenant_id' => null, 'code' => 'GEN', 'name' => 'Generic', 'weekly_hours' => '40.00', 'installments' => 13, 'fourteenth_month' => 0]);
    CollectiveAgreement::withoutGlobalScopes()->create(['tenant_id' => $tenant->id, 'code' => 'OWN', 'name' => 'Own', 'weekly_hours' => '40.00', 'installments' => 13, 'fourteenth_month' => 0]);

    $values = [];
    try {
        hrGridPage(CollectiveAgreement::withoutGlobalScopes(), ['grid_values' => 'is_generic']);
    } catch (HttpResponseException $e) {
        $values = collect($e->getResponse()->getData(true)['data'])->pluck('count', 'value')->all();
    }
    expect($values)->toBe(['0' => 1, '1' => 1]);

    $generic = hrGridPage(CollectiveAgreement::withoutGlobalScopes(), ['filter' => ['is_generic' => ['1']]]);
    expect($generic->pluck('code')->all())->toBe(['GEN']);

    $sorted = hrGridPage(CollectiveAgreement::withoutGlobalScopes(), ['sort_by' => 'is_generic', 'sort_dir' => 'desc']);
    expect($sorted->pluck('code')->all())->toBe(['GEN', 'OWN']);
});

test('claims_count and the payroll run totals are calculated server-side: sort, filter and values', function (): void {
    $tenant = Tenant::factory()->create();

    $busy = ShortTimeCase::factory()->create(['tenant_id' => $tenant->id, 'authority_reference' => 'BUSY']);
    ShortTimeClaim::factory()->count(2)->create(['tenant_id' => $tenant->id, 'short_time_case_id' => $busy->id]);
    ShortTimeCase::factory()->create(['tenant_id' => $tenant->id, 'authority_reference' => 'IDLE']);

    $byClaims = hrGridPage(ShortTimeCase::withoutGlobalScopes(), ['sort_by' => 'claims_count', 'sort_dir' => 'desc']);
    expect($byClaims->pluck('authority_reference')->all())->toBe(['BUSY', 'IDLE']);

    $filtered = hrGridPage(ShortTimeCase::withoutGlobalScopes(), ['filter' => ['claims_count' => ['2']]]);
    expect($filtered->pluck('authority_reference')->all())->toBe(['BUSY']);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'year' => 2026, 'month' => 11]);
    PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'year' => 2026, 'month' => 12]);
    Payslip::factory()->count(2)->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'gross_salary' => '1000.00', 'net_salary' => '800.00']);

    $byEmployees = hrGridPage(PayrollRun::withoutGlobalScopes(), ['sort_by' => 'employees_count', 'sort_dir' => 'desc']);
    expect($byEmployees->first()->id)->toBe($run->id);

    $byGross = hrGridPage(PayrollRun::withoutGlobalScopes(), ['filter' => ['total_gross' => ['2000']]]);
    expect($byGross->pluck('id')->all())->toBe([$run->id]);

    $values = [];
    try {
        hrGridPage(PayrollRun::withoutGlobalScopes(), ['grid_values' => 'total_net']);
    } catch (HttpResponseException $e) {
        $values = collect($e->getResponse()->getData(true)['data'])->pluck('count', 'value')->all();
    }
    expect($values)->toHaveCount(2)->and(array_sum($values))->toBe(2);
});
