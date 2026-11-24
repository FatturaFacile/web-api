<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\HumanResources\Models\ContractSalaryComponent;
use Modules\HumanResources\Models\EmploymentContract;
use Modules\HumanResources\Models\Timesheet;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes;
use Modules\Payroll\Models\PayrollRunEvent;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'admin');
    $this->employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $this->employee);
    payrollSwissSetup($this->tenant, $this->manager);
});

function lifecycleLine(string $runId, string $code): ?PayslipLine
{
    $payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $runId)->sole();

    return PayslipLine::query()->withoutGlobalScopes()->where('payslip_id', $payslip->id)->where('wage_type_code', $code)->first();
}

function lifecycleAccount(Tenant $tenant, string $code, string $type): string
{
    return (string) ChartOfAccount::create([
        'tenant_id' => $tenant->id, 'account_code' => $code, 'name' => "Account {$code}", 'account_class' => $type, 'account_type' => $type,
        'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit', 'currency' => $tenant->default_currency,
    ])->id;
}

function lifecycleBalance(Tenant $tenant, string $code): string
{
    $accountId = ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('account_code', $code)->value('id');

    return number_format((float) JournalLine::withoutGlobalScopes()->where('account_id', $accountId)->selectRaw('COALESCE(SUM(debit_amount - credit_amount), 0) as balance')->value('balance'), 2, '.', '');
}

test('a run is calculated, approved, revoked with a reason and approved again before closing', function (): void {
    $api = fn () => payrollApi($this->manager, $this->tenant);
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->assertJsonPath('data.status', 'Calculated')->json('data.id');

    $api()->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'Approved');
    $api()->postJson("/api/v1/payroll/runs/{$id}/approve")->assertConflict();
    payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertConflict();

    $api()->postJson("/api/v1/payroll/runs/{$id}/reopen", ['reason' => 'Manca un premio'])->assertOk()->assertJsonPath('data.status', 'Calculated')->assertJsonPath('data.approved_by', null);
    payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated();
    $api()->postJson("/api/v1/payroll/runs/{$id}/finalize")->assertUnprocessable();
    $api()->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk();
    $api()->postJson("/api/v1/payroll/runs/{$id}/finalize")->assertOk()->assertJsonPath('data.status', 'Final');
    $api()->postJson("/api/v1/payroll/runs/{$id}/approve")->assertConflict();

    $history = PayrollRunEvent::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->orderBy('occurred_at')->orderBy('id')->get();

    expect($history->pluck('action')->all())->toBe(['Calculated', 'Approved', 'ApprovalRevoked', 'Calculated', 'Approved', 'Finalized'])
        ->and($history->firstWhere('action', 'ApprovalRevoked')?->reason)->toBe('Manca un premio');
});

test('only payroll writers approve, inside their own company', function (): void {
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    $reader = payrollMember($this->tenant, 'member', ['payroll.read']);
    $hr = payrollMember($this->tenant, 'hr');
    $other = payrollTenant();
    $foreign = payrollMember($other, 'admin');

    payrollApi($reader, $this->tenant)->postJson("/api/v1/payroll/runs/{$id}/approve")->assertForbidden();
    payrollApi($hr, $this->tenant)->postJson("/api/v1/payroll/runs/{$id}/approve")->assertForbidden();
    payrollApi($reader, $this->tenant)->getJson("/api/v1/payroll/runs/{$id}/cost-allocation")->assertOk();
    payrollApi($hr, $this->tenant)->getJson('/api/v1/payroll/reports/year-end?year=2026')->assertForbidden();
    payrollApi($foreign, $other)->postJson("/api/v1/payroll/runs/{$id}/approve")->assertNotFound();
    payrollApi($foreign, $other)->getJson("/api/v1/payroll/runs/{$id}/cost-allocation")->assertNotFound();
});

test('monthly salary components of the employment contract enter the payslip while they are valid', function (): void {
    $contract = EmploymentContract::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'is_active' => true, 'start_date' => '2020-01-01']);
    ContractSalaryComponent::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'wage_type_code' => '1100', 'description' => 'Indennità di funzione', 'amount' => '250.00', 'valid_from' => '2026-01-01', 'valid_to' => '2026-01-31']);
    ContractSalaryComponent::factory()->create(['tenant_id' => $this->tenant->id, 'contract_id' => $contract->id, 'wage_type_code' => '1100', 'description' => 'Annuale', 'amount' => '999.00', 'frequency' => 'annual', 'valid_from' => '2026-01-01']);

    $january = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    $february = payrollCalculate($this->tenant, $this->manager, 2026, 2)->assertCreated()->json('data.id');

    expect((string) lifecycleLine($january, '1100')?->amount)->toBe('250.00')
        ->and(Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $january)->sole()->gross_salary)->toBe('6750.00')
        ->and(lifecycleLine($february, '1100'))->toBeNull();
});

test('the labour cost of a run is allocated to projects and cost centres by timesheet hours', function (): void {
    $projectId = (string) str()->uuid();
    $costCenterId = (string) str()->uuid();
    Timesheet::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'project_id' => $projectId, 'cost_center_id' => null, 'work_date' => '2026-01-12', 'total_hours' => '30.00', 'status' => 'approved']);
    Timesheet::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'project_id' => null, 'cost_center_id' => $costCenterId, 'work_date' => '2026-01-13', 'total_hours' => '10.00', 'status' => 'approved']);
    Timesheet::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id, 'project_id' => $projectId, 'work_date' => '2026-01-14', 'total_hours' => '99.00', 'status' => 'submitted']);
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    $payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->sole();
    $cost = bcadd((string) $payslip->gross_salary, (string) $payslip->employer_contributions, 2);

    $allocation = payrollApi($this->manager, $this->tenant)->getJson("/api/v1/payroll/runs/{$id}/cost-allocation")->assertOk()->json('data');
    $totals = collect($allocation['totals'])->keyBy('target_id');

    expect($allocation['lines'])->toHaveCount(2)
        ->and(bcadd($totals[$projectId]['amount'], $totals[$costCenterId]['amount'], 2))->toBe($cost)
        ->and($totals[$projectId]['amount'])->toBe(bcdiv(bcmul($cost, '0.75', 4), '1', 2));
});

test('the year-end summary totals the closed runs per employee as JSON or CSV', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    payrollCalculate($this->tenant, $this->manager, 2026, 2)->assertCreated();

    $summary = payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/reports/year-end?year=2026')->assertOk()->json('data.employees.0');

    expect($summary['months'])->toBe([1])
        ->and($summary['gross_salary'])->toBe('6500.00')
        ->and($summary['amounts_by_code'][SwissWageTypeCodes::MonthlySalary])->toBe('6500.00');

    $csv = payrollApi($this->manager, $this->tenant)->get('/api/v1/payroll/reports/year-end?year=2026&format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();

    expect($csv)->toContain('wage_type_code')->toContain('TOTAL')->toContain('6500.00');
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/reports/year-end')->assertUnprocessable();
});

test('a run calculated with configured test tables is approved, closed and posted to the ledger', function (): void {
    // Sample values for the test, not statutory rates: the engine reads whatever the company configures (contribution
    // rates in the company settings, thresholds in the annual table of the year).
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/payroll/settings/swiss', ['ahv_employee_rate_percent' => '5', 'alv_employee_rate_percent' => '1'])->assertOk();
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/payroll/annual-parameters/CH/2026', [
        'parameters' => ['bvg_entry_threshold' => '22000', 'bvg_coordination_deduction' => '26000', 'bvg_maximum_salary' => '90000', 'alv_max_annual_salary' => '150000'],
        'legal_reference' => 'Test table',
    ])->assertOk();
    app(OpenFiscalYearAction::class)->execute($this->tenant->id, 2026);
    payrollApi($this->manager, $this->tenant)->putJson('/api/v1/general-ledger/payroll-settings', [
        'salary_expense_account_id' => lifecycleAccount($this->tenant, '6400', 'expense'),
        'employer_cost_account_id' => lifecycleAccount($this->tenant, '6410', 'expense'),
        'expense_allowance_account_id' => lifecycleAccount($this->tenant, '6420', 'expense'),
        'employee_payable_account_id' => lifecycleAccount($this->tenant, '2300', 'liability'),
        'social_payable_account_id' => lifecycleAccount($this->tenant, '2310', 'liability'),
        'tax_payable_account_id' => lifecycleAccount($this->tenant, '2320', 'liability'),
        'rounding_account_id' => lifecycleAccount($this->tenant, '6960', 'expense'),
    ])->assertOk();

    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $run->id)->sole();
    $entry = JournalEntry::withoutGlobalScopes()->where('source_document_type', 'payroll_run')->where('source_document_id', $run->id)->sole();

    expect((string) lifecycleLine($run->id, SwissWageTypeCodes::Ahv)?->amount)->toBe('-325.00')
        ->and((string) lifecycleLine($run->id, SwissWageTypeCodes::Alv)?->amount)->toBe('-65.00')
        ->and($entry->isBalanced())->toBeTrue()
        ->and(lifecycleBalance($this->tenant, '6400'))->toBe((string) $payslip->gross_salary)
        ->and(lifecycleBalance($this->tenant, '2300'))->toBe('-'.$payslip->payout_amount)
        ->and((float) lifecycleBalance($this->tenant, '2310'))->toBeLessThan(0.0);
});
