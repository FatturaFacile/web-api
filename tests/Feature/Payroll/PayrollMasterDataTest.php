<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;
use Modules\Payroll\Models\PayrollAbsence;
use Modules\Payroll\Models\PayrollEmployeeChild;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollWageType;
use Modules\Payroll\Models\SwissPayrollSettings;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'owner');
    $this->employee = payrollEmployee($this->tenant);
});

test('the setup creates the desktop catalog once and keeps what the company changed', function (): void {
    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/setup')->assertOk()->assertJsonPath('data.changed', true);

    expect(PayrollWageType::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(35)
        ->and(SwissPayrollSettings::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->sole()->ahv_employee_rate_percent)->toBe('5.3000');

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/setup')->assertOk()->assertJsonPath('data.changed', false);
});

test('Swiss settings follow the rules of the desktop validator', function (): void {
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/payroll/settings/swiss', ['bvg_employer_share_percent' => 40])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bvg_employer_share_percent');
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/payroll/settings/swiss', ['default_work_canton' => 'XX'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('default_work_canton');
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/payroll/settings/swiss', ['ahv_employee_rate_percent' => '5.4'])
        ->assertOk()
        ->assertJsonPath('data.ahv_employee_rate_percent', '5.4000')
        ->assertJsonPath('data.alv_annual_ceiling', '148200.00');
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/settings/swiss')->assertOk()->assertJsonPath('data.ahv_employee_rate_percent', '5.4000');
});

test('Italian settings hold the INPS and INAIL codes with the desktop defaults', function (): void {
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/settings/italian')->assertOk()->assertJsonPath('data', null);
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/payroll/settings/italian', ['inps_company_number' => '123', 'inail_code' => '998'])
        ->assertOk()
        ->assertJsonPath('data.employee_inps_rate_percent', '9.1900')
        ->assertJsonPath('data.employer_inps_rate_percent', '29.0000')
        ->assertJsonPath('data.inail_code', '998');
});

test('system wage types keep their code and cannot be deleted', function (): void {
    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/setup')->assertOk();
    $system = PayrollWageType::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('code', '1000')->sole();

    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/wage-types/{$system->id}", ['code' => '1001', 'name' => 'Altro', 'kind' => 'Earning'])
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'Il codice di un genere salariale di sistema non può essere modificato.');
    payrollApi($this->manager, $this->tenant)->deleteJson("/api/v1/payroll/wage-types/{$system->id}")->assertUnprocessable();
    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/wage-types/{$system->id}", ['code' => '1000', 'name' => 'Stipendio', 'kind' => 'Earning'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Stipendio');

    $own = payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/wage-types', ['code' => 'X1', 'name' => 'Mia voce', 'kind' => 'Earning', 'subject_to_ahv' => true])
        ->assertCreated()
        ->json('data.id');
    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/wage-types', ['code' => 'bad code', 'name' => 'Mia voce', 'kind' => 'Earning'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('code');
    payrollApi($this->manager, $this->tenant)->deleteJson("/api/v1/payroll/wage-types/{$own}")->assertNoContent();
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/wage-types?per_page=200')->assertOk()->assertJsonCount(35, 'data');
});

test('collective agreements and their levels can be managed', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);

    $id = $api()->postJson('/api/v1/payroll/collective-agreements', ['code' => 'H011', 'name' => 'Commercio'])
        ->assertCreated()
        ->assertJsonPath('data.is_generic', false)
        ->json('data.id');
    $api()->postJson('/api/v1/payroll/collective-agreements', ['code' => 'H011', 'name' => 'Commercio', 'installments' => 15])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('installments');
    $level = $api()->postJson("/api/v1/payroll/collective-agreements/{$id}/levels", ['level' => '4', 'monthly_minimum' => '1800.50'])
        ->assertCreated()
        ->assertJsonPath('data.monthly_minimum', '1800.50')
        ->json('data.id');
    $api()->putJson("/api/v1/payroll/collective-agreements/{$id}/levels/{$level}", ['level' => '4', 'monthly_minimum' => '1900'])->assertOk()->assertJsonPath('data.monthly_minimum', '1900.00');
    $api()->putJson("/api/v1/payroll/collective-agreements/{$id}", ['code' => 'H011', 'name' => 'Terziario', 'fourteenth_month' => 6])->assertOk()->assertJsonPath('data.fourteenth_month', 6);
    $api()->getJson("/api/v1/payroll/collective-agreements/{$id}")->assertOk()->assertJsonCount(1, 'data.levels')->assertJsonPath('data.name', 'Terziario');
    $api()->getJson('/api/v1/payroll/collective-agreements')->assertOk()->assertJsonCount(1, 'data');
    $api()->deleteJson("/api/v1/payroll/collective-agreements/{$id}/levels/{$level}")->assertNoContent();
    $api()->deleteJson("/api/v1/payroll/collective-agreements/{$id}")->assertNoContent();

    expect(CollectiveAgreement::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(CollectiveAgreementLevel::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('inputs, absences and children can be managed by employee_id', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);
    $employeeId = $this->employee->id;

    $input = $api()->postJson('/api/v1/payroll/inputs', ['employee_id' => $employeeId, 'year' => 2026, 'month' => 3, 'wage_type_code' => '1010', 'quantity' => '10', 'rate' => '45.5'])
        ->assertCreated()->json('data.id');
    $api()->putJson("/api/v1/payroll/inputs/{$input}", ['employee_id' => $employeeId, 'year' => 2026, 'month' => 3, 'wage_type_code' => '1010', 'quantity' => '12'])
        ->assertOk()->assertJsonPath('data.quantity', '12.0000');
    $api()->getJson("/api/v1/payroll/inputs?employee_id={$employeeId}&year=2026&month=3")->assertOk()->assertJsonCount(1, 'data');
    $api()->deleteJson("/api/v1/payroll/inputs/{$input}")->assertNoContent();

    $absence = $api()->postJson('/api/v1/payroll/absences', ['employee_id' => $employeeId, 'absence_type' => 'Unpaid', 'start_date' => '2026-03-02', 'end_date' => '2026-03-03'])
        ->assertCreated()->assertJsonPath('data.start_date', '2026-03-02')->json('data.id');
    $api()->postJson('/api/v1/payroll/absences', ['employee_id' => $employeeId, 'absence_type' => 'Holiday', 'start_date' => '2026-03-03', 'end_date' => '2026-03-02'])
        ->assertUnprocessable()->assertJsonValidationErrors(['absence_type', 'end_date']);
    $api()->putJson("/api/v1/payroll/absences/{$absence}", ['employee_id' => $employeeId, 'absence_type' => 'Unpaid', 'start_date' => '2026-03-02', 'end_date' => '2026-03-04', 'percent' => 50])
        ->assertOk()->assertJsonPath('data.percent', '50.00');
    $api()->getJson("/api/v1/payroll/absences?employee_id={$employeeId}")->assertOk()->assertJsonCount(1, 'data');
    $api()->deleteJson("/api/v1/payroll/absences/{$absence}")->assertNoContent();

    $child = $api()->postJson('/api/v1/payroll/children', ['employee_id' => $employeeId, 'first_name' => 'Luca', 'date_of_birth' => '2020-01-01'])
        ->assertCreated()->assertJsonPath('data.first_name', 'Luca')->json('data.id');
    $api()->postJson('/api/v1/payroll/children', ['employee_id' => $employeeId, 'first_name' => 'Sara', 'date_of_birth' => '2030-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
    $api()->putJson("/api/v1/payroll/children/{$child}", ['employee_id' => $employeeId, 'first_name' => 'Luca', 'date_of_birth' => '2020-01-01', 'in_education' => true])
        ->assertOk()->assertJsonPath('data.in_education', true);
    $api()->getJson("/api/v1/payroll/children?employee_id={$employeeId}")->assertOk()->assertJsonCount(1, 'data');
    $api()->deleteJson("/api/v1/payroll/children/{$child}")->assertNoContent();

    expect(PayrollInput::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(PayrollAbsence::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(PayrollEmployeeChild::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('employee profiles are listed, shown and saved', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);

    $api()->getJson("/api/v1/payroll/employee-profiles/{$this->employee->id}")->assertOk()->assertJsonPath('data', null);
    $api()->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['salary_installments_per_year' => 13, 'work_canton' => 'TI'])
        ->assertOk()
        ->assertJsonPath('data.salary_installments_per_year', 13);
    $api()->getJson("/api/v1/payroll/employee-profiles?employee_id={$this->employee->id}")->assertOk()->assertJsonCount(1, 'data');
    $api()->getJson("/api/v1/payroll/employee-profiles/{$this->employee->id}")->assertOk()->assertJsonPath('data.work_canton', 'TI');
});

test('the legal tables can be read', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);

    $api()->getJson('/api/v1/payroll/annual-parameters')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.is_generic', true);
    $api()->getJson('/api/v1/payroll/family-allowance-rates?per_page=200')->assertOk()->assertJsonCount(26, 'data');
    $api()->getJson('/api/v1/payroll/withholding-tax-tariffs')->assertOk()->assertJsonCount(0, 'data');
});
