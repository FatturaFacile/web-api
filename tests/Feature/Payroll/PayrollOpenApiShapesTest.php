<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Models\PayrollRun;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

/**
 * The payroll resources answer with a bare Laravel resource (`{data: ...}`, `{data: [...], links, meta}` for lists),
 * decimals as strings and dates as Y-m-d. These tests pin the fields documented in PayrollResponseSchemas.
 */
beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'admin');
    $this->employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $this->employee);
    payrollSwissSetup($this->tenant, $this->manager);
});

/**
 * @param  array<string, mixed>  $data
 * @param  list<string>  $keys
 */
function payrollExpectKeys(array $data, array $keys): void
{
    expect(array_keys($data))->toEqualCanonicalizing($keys);
}

test('runs, payslips and their events keep the documented fields and the capitalised status', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);
    $runKeys = ['id', 'tenant_id', 'year', 'month', 'country', 'currency', 'status', 'payment_date', 'calculated_at', 'approved_at', 'approved_by', 'finalized_at', 'finalized_by', 'reopened_at', 'reopened_by', 'reopen_reason', 'reopen_count', 'created_at', 'updated_at', 'is_generic'];

    $calculated = $api()->postJson('/api/v1/payroll/runs/calculate', ['year' => 2026, 'month' => 1])->assertCreated()->assertJsonMissingPath('success');
    $id = $calculated->json('data.id');
    payrollExpectKeys($calculated->json('data'), [...$runKeys, 'payslips']);
    expect($calculated->json('data.status'))->toBeIn(['Draft', 'Calculated', 'Approved', 'Final']);

    $list = $api()->getJson('/api/v1/payroll/runs')->assertOk()->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']]);
    payrollExpectKeys($list->json('data.0'), $runKeys);

    $show = $api()->getJson("/api/v1/payroll/runs/{$id}")->assertOk();
    payrollExpectKeys($show->json('data'), [...$runKeys, 'payslips', 'events']);

    $payslipKeys = ['id', 'tenant_id', 'payroll_run_id', 'employee_id', 'year', 'month', 'employee_name', 'paid_days', 'gross_salary', 'ahv_basis', 'alv_basis', 'uvg_basis', 'ktg_basis', 'bvg_insured_salary', 'withholding_tax_basis', 'withholding_tax_rate_percent', 'withholding_tax_code', 'withholding_tax_canton', 'employee_deductions', 'net_salary', 'payout_amount', 'employer_contributions', 'warnings', 'created_at', 'updated_at', 'is_generic'];
    payrollExpectKeys($show->json('data.payslips.0'), $payslipKeys);
    payrollExpectKeys($show->json('data.events.0'), ['id', 'tenant_id', 'payroll_run_id', 'action', 'reason', 'totals', 'actor_id', 'actor_name', 'occurred_at', 'created_at', 'is_generic']);
    expect($show->json('data.payslips.0.gross_salary'))->toBeString();

    $payslipId = $show->json('data.payslips.0.id');
    $detail = $api()->getJson("/api/v1/payroll/payslips/{$payslipId}")->assertOk();
    payrollExpectKeys($detail->json('data'), [...$payslipKeys, 'lines']);
    payrollExpectKeys($detail->json('data.lines.0'), ['id', 'tenant_id', 'payslip_id', 'wage_type_code', 'description', 'kind', 'quantity', 'rate', 'amount', 'certificate_box', 'sort_order', 'created_at', 'updated_at', 'is_generic']);
    payrollExpectKeys($api()->getJson('/api/v1/payroll/payslips')->assertOk()->json('data.0'), $payslipKeys);

    $approved = $api()->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk();
    expect($approved->json('data.approved_at'))->toContain('T')
        ->and($api()->getJson("/api/v1/payroll/runs/{$id}")->json('data.approved_at'))->toContain('T')
        ->and($approved->json('data.calculated_at'))->toContain('T');

    expect($approved->json('data.status'))->toBe('Approved')
        ->and($api()->postJson("/api/v1/payroll/runs/{$id}/finalize")->assertOk()->json('data.status'))->toBe('Final')
        ->and(PayrollRun::query()->withoutGlobalScopes()->findOrFail($id)->status)->toBe('Final');
});

test('the year-end summary and the cost allocation have the documented shape', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    $year = $api()->getJson('/api/v1/payroll/reports/year-end?year=2026')->assertOk();
    payrollExpectKeys($year->json('data'), ['year', 'employees']);
    payrollExpectKeys($year->json('data.employees.0'), ['employee_id', 'employee_name', 'months', 'gross_salary', 'employee_deductions', 'net_salary', 'payout_amount', 'employer_contributions', 'amounts_by_code']);

    $allocation = $api()->getJson("/api/v1/payroll/runs/{$run->id}/cost-allocation")->assertOk();
    payrollExpectKeys($allocation->json('data'), ['run_id', 'year', 'month', 'currency', 'lines', 'totals']);
    payrollExpectKeys($allocation->json('data.lines.0'), ['employee_id', 'employee_name', 'target_type', 'target_id', 'hours', 'amount']);

    $simulated = $api()->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 2])->assertOk();
    payrollExpectKeys($simulated->json('data.0'), ['employee_id', 'employee_name', 'year', 'month', 'paid_days', 'gross_salary', 'ahv_basis', 'alv_basis', 'uvg_basis', 'ktg_basis', 'bvg_insured_salary', 'withholding_tax_basis', 'withholding_tax_rate_percent', 'withholding_tax_code', 'withholding_tax_canton', 'employee_deductions', 'net_salary', 'payout_amount', 'employer_contributions', 'warnings', 'lines']);
    payrollExpectKeys($simulated->json('data.0.lines.0'), ['wage_type_code', 'description', 'kind', 'quantity', 'rate', 'amount']);

    $preview = $api()->postJson('/api/v1/payroll/runs/preview', ['employee_id' => $this->employee->id])->assertOk();
    payrollExpectKeys($preview->json('data'), ['country_code', 'currency', 'gross_monthly_salary', 'total_deductions', 'net_monthly_salary', 'employer_total_cost', 'deduction_breakdown', 'compliance_notes']);
});

test('salary certificates have the documented boxes', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $api()->postJson('/api/v1/payroll/salary-certificates/generate', ['year' => 2026])->assertOk()->assertJsonPath('data.generated', 1);

    $keys = ['id', 'tenant_id', 'employee_id', 'year', 'period_from', 'period_to', 'box1_salary', 'box2_benefits', 'box3_irregular', 'box4_capital', 'box7_other', 'box8_gross', 'box9_social_contributions', 'box10_pension', 'box11_net', 'box12_withholding_tax', 'box13_expenses', 'remarks', 'generated_at', 'created_at', 'updated_at', 'is_generic'];
    $list = $api()->getJson('/api/v1/payroll/salary-certificates')->assertOk();
    payrollExpectKeys($list->json('data.0'), $keys);
    payrollExpectKeys($api()->getJson('/api/v1/payroll/salary-certificates/'.$list->json('data.0.id'))->assertOk()->json('data'), $keys);
});

test('agreements, wage types, family allowances, inputs, absences and children keep the documented fields', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);
    $employeeId = $this->employee->id;
    $ts = ['created_at', 'updated_at', 'is_generic'];

    $agreement = $api()->postJson('/api/v1/payroll/collective-agreements', ['code' => 'H011', 'name' => 'Commercio'])->assertCreated();
    $agreementKeys = ['id', 'tenant_id', 'code', 'name', 'weekly_hours', 'installments', 'fourteenth_month', 'employer_inps_rate_percent', 'notes', ...$ts];
    $api()->postJson('/api/v1/payroll/collective-agreements/'.$agreement->json('data.id').'/levels', ['level' => '4', 'monthly_minimum' => '1800.50'])->assertCreated();
    $levelKeys = ['id', 'tenant_id', 'agreement_id', 'level', 'description', 'monthly_minimum', ...$ts];
    $detail = $api()->getJson('/api/v1/payroll/collective-agreements/'.$agreement->json('data.id'))->assertOk();
    payrollExpectKeys($detail->json('data'), [...$agreementKeys, 'levels']);
    payrollExpectKeys($detail->json('data.levels.0'), $levelKeys);
    payrollExpectKeys($api()->getJson('/api/v1/payroll/collective-agreements')->assertOk()->json('data.0'), [...$agreementKeys, 'levels']);

    $wageKeys = ['id', 'tenant_id', 'code', 'name', 'kind', 'subject_to_ahv', 'subject_to_alv', 'subject_to_uvg', 'subject_to_ktg', 'subject_to_bvg', 'subject_to_withholding_tax', 'is_irregular', 'certificate_box', 'sort_order', 'is_system', 'is_active', ...$ts];
    payrollExpectKeys($api()->getJson('/api/v1/payroll/wage-types')->assertOk()->json('data.0'), $wageKeys);
    $createdWage = $api()->postJson('/api/v1/payroll/wage-types', ['code' => 'X1', 'name' => 'Mia voce', 'kind' => 'Earning'])->assertCreated()->json('data');
    payrollExpectKeys($createdWage, $wageKeys);

    $input = $api()->postJson('/api/v1/payroll/inputs', ['employee_id' => $employeeId, 'year' => 2026, 'month' => 3, 'wage_type_code' => '1010', 'quantity' => '10', 'rate' => '45.5'])->assertCreated();
    $inputKeys = ['id', 'tenant_id', 'employee_id', 'year', 'month', 'wage_type_code', 'quantity', 'rate', 'amount', 'note', ...$ts];
    payrollExpectKeys($input->json('data'), $inputKeys);
    payrollExpectKeys($api()->getJson('/api/v1/payroll/inputs')->assertOk()->json('data.0'), $inputKeys);

    $absence = $api()->postJson('/api/v1/payroll/absences', ['employee_id' => $employeeId, 'absence_type' => 'Unpaid', 'start_date' => '2026-03-02', 'end_date' => '2026-03-03'])->assertCreated();
    $absenceKeys = ['id', 'tenant_id', 'employee_id', 'absence_type', 'start_date', 'end_date', 'percent', 'note', ...$ts];
    payrollExpectKeys($absence->json('data'), $absenceKeys);
    payrollExpectKeys($api()->getJson('/api/v1/payroll/absences')->assertOk()->json('data.0'), $absenceKeys);

    $api()->postJson('/api/v1/payroll/children', ['employee_id' => $employeeId, 'first_name' => 'Luca', 'date_of_birth' => '2020-01-01'])->assertCreated();
    payrollExpectKeys($api()->getJson('/api/v1/payroll/children')->assertOk()->json('data.0'), ['id', 'tenant_id', 'employee_id', 'first_name', 'last_name', 'date_of_birth', 'in_education', 'education_end_date', 'allowance_eligible', ...$ts]);

    payrollExpectKeys($api()->getJson('/api/v1/payroll/family-allowance-rates')->assertOk()->json('data.0'), ['id', 'tenant_id', 'canton', 'valid_from', 'child_allowance', 'education_allowance', ...$ts]);

    $profile = $api()->getJson("/api/v1/payroll/employee-profiles/{$employeeId}")->assertOk();
    expect($profile->json('data'))->toBeArray()->and($profile->json('data.employee_id'))->toBe($employeeId);
});
