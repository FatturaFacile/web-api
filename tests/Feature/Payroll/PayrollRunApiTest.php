<?php

declare(strict_types=1);

use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Payroll\Calculation\Italian\ItalianWageCodes;
use Modules\Payroll\Calculation\Swiss\SwissWageTypeCodes;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\CollectiveAgreementLevel;
use Modules\Payroll\Models\PayrollAnnualParameter;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollRunEvent;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'admin');
});

function payrollLine(string $payslipId, string $code): ?PayslipLine
{
    return PayslipLine::query()->withoutGlobalScopes()->where('payslip_id', $payslipId)->where('wage_type_code', $code)->first();
}

test('a Swiss run gives the payslip of the desktop test case and stores its lines', function (): void {
    $employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $employee);
    payrollSwissSetup($this->tenant, $this->manager);

    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)
        ->assertCreated()
        ->assertJsonPath('data.status', 'Draft')
        ->assertJsonPath('data.country', 'CH')
        ->assertJsonPath('data.currency', 'CHF')
        ->json('data.id');

    $payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->sole();

    expect($payslip->gross_salary)->toBe('6500.00')
        ->and($payslip->bvg_insured_salary)->toBe('58040.00')
        ->and($payslip->employee_deductions)->toBe('768.35')
        ->and($payslip->net_salary)->toBe('5731.65')
        ->and($payslip->payout_amount)->toBe('5731.65')
        ->and($payslip->warnings)->toBeNull()
        ->and(payrollLine($payslip->id, SwissWageTypeCodes::Ahv)->amount)->toBe('-344.50')
        ->and(payrollLine($payslip->id, SwissWageTypeCodes::Bvg)->amount)->toBe('-241.85')
        ->and(payrollLine($payslip->id, SwissWageTypeCodes::BvgEmployer)->amount)->toBe('241.85');

    payrollApi($this->manager, $this->tenant)->getJson("/api/v1/payroll/runs/{$id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.payslips')
        ->assertJsonPath('data.events.0.action', 'Calculated');
});

test('the year-to-date values of the previous months reach the ceilings and the 13th salary', function (): void {
    $employee = payrollEmployee($this->tenant, ['base_annual_salary' => '180000.00']);
    payrollProfile($this->tenant, $employee, ['salary_installments_per_year' => 12]);
    payrollSwissSetup($this->tenant, $this->manager);

    payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated();
    payrollCalculate($this->tenant, $this->manager, 2026, 2)->assertCreated();

    $slips = Payslip::query()->withoutGlobalScopes()->where('employee_id', $employee->id)->orderBy('month')->get();

    expect($slips[0]->alv_basis)->toBe('12350.00')
        ->and($slips[1]->alv_basis)->toBe('12350.00');
});

test('recalculating a draft replaces its payslips and picks up the inputs', function (): void {
    $employee = payrollEmployee($this->tenant, ['base_annual_salary' => '78000.00']);
    payrollProfile($this->tenant, $employee, ['salary_installments_per_year' => 12]);
    payrollSwissSetup($this->tenant, $this->manager);

    $id = payrollCalculate($this->tenant, $this->manager, 2026, 3)->assertCreated()->json('data.id');

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/inputs', [
        'employee_id' => $employee->id,
        'year' => 2026,
        'month' => 3,
        'wage_type_code' => '1100',
        'amount' => '1000',
        'note' => 'Premio',
    ])->assertCreated();

    expect(payrollCalculate($this->tenant, $this->manager, 2026, 3)->assertCreated()->json('data.id'))->toBe($id);

    $payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->sole();

    expect($payslip->gross_salary)->toBe('7500.00')
        ->and(payrollLine($payslip->id, '1100')->description)->toBe('Gratifica / bonus - Premio')
        ->and(PayrollRunEvent::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->count())->toBe(2);
});

test('a Swiss company cannot calculate before the setup', function (): void {
    payrollEmployee($this->tenant);

    payrollCalculate($this->tenant, $this->manager, 2026, 1)
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'Impostazioni paghe mancanti: aprire Paghe > Impostazioni.');

    expect(PayrollRun::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('the simulation runs the engine and saves nothing', function (): void {
    $employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $employee);
    payrollEmployee($this->tenant, ['first_name' => 'Anna', 'last_name' => 'Bianchi', 'full_name' => 'Anna Bianchi']);
    payrollSwissSetup($this->tenant, $this->manager);

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 1])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 1, 'employee_id' => $employee->id])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.gross_salary', '6500.00')
        ->assertJsonPath('data.0.net_salary', '5731.65');

    expect(PayrollRun::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0)
        ->and(Payslip::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('the preview is the country strategy of the desktop', function (): void {
    $tenant = payrollTenant('IT');
    $manager = payrollMember($tenant, 'owner');
    $employee = payrollEmployee($tenant, ['base_annual_salary' => '36000.00']);

    payrollApi($manager, $tenant)->postJson('/api/v1/payroll/runs/preview', ['employee_id' => $employee->id, 'target_month' => '2026-01-01'])
        ->assertOk()
        ->assertJsonPath('data.currency', 'EUR')
        ->assertJsonPath('data.deduction_breakdown.Contributi INPS dipendente', '275.70')
        ->assertJsonPath('data.deduction_breakdown.IRPEF netta', '458.92')
        ->assertJsonPath('data.net_monthly_salary', '2265.38');
});

test('closing a run publishes the domain event accounting subscribes to', function (): void {
    $employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $employee);
    payrollSwissSetup($this->tenant, $this->manager);
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->json('data.id');

    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$id}/finalize")
        ->assertOk()
        ->assertJsonPath('data.status', 'Final')
        ->assertJsonPath('data.finalized_by', $this->manager->id);

    $event = TransactionalOutbox::query()->where('aggregate_id', $id)->where('event_type', 'accounting.payroll.run_finalized')->sole();

    expect($event->tenant_id)->toBe($this->tenant->id);

    $payload = is_array($event->payload_json) ? $event->payload_json : json_decode((string) $event->payload_json, true);

    expect($payload['totals']['gross_salary'])->toBe('6500.00')
        ->and($payload['totals']['payout_amount'])->toBe('5731.65')
        ->and($payload['amountsByCode'][SwissWageTypeCodes::Ahv])->toBe('-344.50')
        ->and($payload['currency'])->toBe('CHF');
});

test('an empty run cannot be closed', function (): void {
    payrollSwissSetup($this->tenant, $this->manager);
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');

    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$id}/finalize")
        ->assertUnprocessable()
        ->assertJsonPath('detail', "Calcolare l'elaborazione prima di chiuderla.");
});

test('an Italian run applies the agreement, warns below the minimum and withholds last year surcharges', function (): void {
    $tenant = payrollTenant('IT');
    $manager = payrollMember($tenant, 'owner');
    $employee = payrollEmployee($tenant, ['base_annual_salary' => '30000.00']);
    payrollProfile($tenant, $employee, [
        'collective_agreement_code' => 'h011',
        'contract_level' => '4',
        'regional_surcharge_rate_percent' => '1.73',
        'municipal_surcharge_rate_percent' => '0.8',
    ]);

    $agreement = payrollApi($manager, $tenant)->postJson('/api/v1/payroll/collective-agreements', [
        'code' => 'H011', 'name' => 'Commercio', 'installments' => 13, 'employer_inps_rate_percent' => '28.5',
    ])->assertCreated()->json('data.id');
    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/collective-agreements/{$agreement}/levels", ['level' => '4', 'monthly_minimum' => '2500'])->assertCreated();

    $january = payrollCalculate($tenant, $manager, 2026, 1)->assertCreated()->json('data.id');
    $march = payrollCalculate($tenant, $manager, 2026, 3)->assertCreated()->json('data.id');
    expect(payrollCalculate($tenant, $manager, 2027, 2)->assertCreated()->json('data.status'))->toBe('Draft');

    $first = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $january)->sole();
    $third = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $march)->sole();
    $nextYear = Payslip::query()->withoutGlobalScopes()->where('year', 2027)->where('tenant_id', $tenant->id)->sole();

    expect($first->gross_salary)->toBe('2307.69')
        ->and($first->ahv_basis)->toBe('2308.00')
        ->and($first->warnings)->toContain('inferiore al minimo del livello 4')
        ->and(payrollLine($first->id, ItalianWageCodes::InpsEmployee)->amount)->toBe('-212.11')
        ->and(payrollLine($first->id, ItalianWageCodes::InpsEmployer)->rate)->toBe('28.5000')
        ->and(payrollLine($third->id, ItalianWageCodes::Irpef)->amount)->toBe(payrollLine($first->id, ItalianWageCodes::Irpef)->amount)
        ->and(payrollLine($nextYear->id, ItalianWageCodes::RegionalSurcharge))->not->toBeNull()
        ->and($nextYear->warnings)->toContain('Parametri legali 2026 usati per il 2027');
});

test('a company row of the annual parameters wins over the generic one', function (): void {
    $tenant = payrollTenant('IT');
    $manager = payrollMember($tenant, 'owner');
    payrollProfile($tenant, payrollEmployee($tenant, ['base_annual_salary' => '30000.00']));

    payrollApi($manager, $tenant)->putJson('/api/v1/payroll/annual-parameters/IT/2027', [
        'parameters' => ['inps_first_bracket' => '60000', 'irpef_first_rate' => '0.22'],
        'legal_reference' => 'Prova',
    ])->assertOk()->assertJsonPath('data.is_generic', false);

    payrollApi($manager, $tenant)->putJson('/api/v1/payroll/annual-parameters/IT/2027', ['parameters' => ['unknown_key' => '1']])
        ->assertUnprocessable();

    $id = payrollCalculate($tenant, $manager, 2027, 1)->assertCreated()->json('data.id');
    $payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->sole();

    expect($payslip->warnings)->toBeNull();

    $generic = PayrollAnnualParameter::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('country', 'IT')->sole();

    expect($generic->year)->toBe(2026)
        ->and($generic->parameters['inps_first_bracket'])->toBe('56224');
});

test('the variable inputs reject a month without quantity and amount', function (): void {
    $employee = payrollEmployee($this->tenant);

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/inputs', [
        'employee_id' => $employee->id, 'year' => 2026, 'month' => 13, 'wage_type_code' => '1100', 'amount' => '10',
    ])->assertUnprocessable()->assertJsonValidationErrors('month');

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/inputs', [
        'employee_id' => $employee->id, 'year' => 2026, 'month' => 3, 'wage_type_code' => '1100',
    ])->assertUnprocessable()->assertJsonValidationErrors('amount');

    expect(PayrollInput::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(0);
});

test('company agreements win over generic ones with the same code', function (): void {
    $tenant = payrollTenant('IT');
    $manager = payrollMember($tenant, 'owner');
    $generic = CollectiveAgreement::factory()->generic()->create(['code' => 'H011', 'name' => 'Generico', 'installments' => 12]);
    CollectiveAgreementLevel::query()->create(['tenant_id' => null, 'agreement_id' => $generic->id, 'level' => '4', 'monthly_minimum' => '1000']);
    payrollProfile($tenant, payrollEmployee($tenant, ['base_annual_salary' => '26000.00']), ['collective_agreement_code' => 'H011', 'contract_level' => '4']);

    payrollCalculate($tenant, $manager, 2026, 1)->assertCreated();
    expect(Payslip::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('month', 1)->sole()->gross_salary)->toBe('2166.67');

    payrollApi($manager, $tenant)->postJson('/api/v1/payroll/collective-agreements', ['code' => 'H011', 'name' => 'Aziendale', 'installments' => 13])->assertCreated();

    payrollCalculate($tenant, $manager, 2026, 2)->assertCreated();
    expect(Payslip::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('month', 2)->sole()->gross_salary)->toBe('2000.00');
});
