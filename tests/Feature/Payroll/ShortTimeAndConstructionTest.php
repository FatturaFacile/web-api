<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollInput;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\ShortTimeClaim;
use Modules\Payroll\Services\ShortTime\ShortTimeClaimService;
use Tests\Support\RowLocks;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->otherTenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'owner');
    $this->otherManager = payrollMember($this->otherTenant, 'owner');
    payrollSwissSetup($this->tenant, $this->manager);
    $this->employee = payrollEmployee($this->tenant, ['base_annual_salary' => '72000.00']);
    payrollProfile($this->tenant, $this->employee, ['weekly_hours' => '42', 'salary_installments_per_year' => 12]);
    $this->colleague = payrollEmployee($this->tenant, ['base_annual_salary' => '72000.00']);
    payrollProfile($this->tenant, $this->colleague, ['weekly_hours' => '42']);
});

function stApi(): mixed
{
    return payrollApi(test()->manager, test()->tenant);
}

/**
 * @param  array<string, mixed>  $values
 */
function stCase(array $values = []): string
{
    return stApi()->postJson('/api/v1/payroll/short-time/cases', ['kind' => 'short_time', 'cause' => 'Calo degli ordini', 'notified_on' => '2026-02-01', 'starts_on' => '2026-03-01', ...$values])->assertCreated()->json('data.id');
}

/**
 * @param  list<array<string, mixed>>  $employees
 */
function stClaim(string $case, array $employees, int $month = 3): mixed
{
    return stApi()->postJson("/api/v1/payroll/short-time/cases/{$case}/claims", ['year' => 2026, 'month' => $month, 'employees' => $employees]);
}

/**
 * @return array<string, mixed>
 */
function stHours(string $employeeId, int $worked = 136): array
{
    return ['employee_id' => $employeeId, 'target_hours' => 176, 'worked_hours' => $worked];
}

test('a claim computes the compensation per employee with the parameters of the year and their warnings', function (): void {
    $claim = stClaim(stCase(), [stHours($this->employee->id)])->assertCreated()->json('data');

    expect($claim['loss_percent'])->toBe('22.73')
        ->and($claim['meets_minimum_loss'])->toBeTrue()
        ->and($claim['total_compensation'])->toBe('861.82')
        ->and($claim['parameters_year'])->toBe(2025)
        ->and($claim['claim_due_on'])->toBe('2026-06-30')
        ->and($claim['lines'][0])->toMatchArray(['lost_hours' => '40.00', 'waiting_hours' => '8.40', 'compensable_hours' => '31.60', 'compensation' => '861.82', 'employer_refund' => '0.00'])
        ->and(implode(' ', $claim['warnings']))->toContain('2025')->toContain('employer_refund_basis');
});

test('below the minimum loss nothing is due, and such a claim can be neither submitted nor applied', function (): void {
    $claim = stClaim(stCase(), [stHours($this->employee->id, 170)])->assertCreated()->json('data');

    expect($claim['meets_minimum_loss'])->toBeFalse()->and($claim['total_compensation'])->toBe('0.00')
        ->and($claim['lines'][0]['compensation'])->toBe('0.00');

    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim['id']}/submit")->assertUnprocessable();
    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim['id']}/apply")->assertUnprocessable();
});

test('the people without a right are excluded with the reason and do not enter the minimum loss', function (array $attributes, array $profile, string $reason): void {
    $excluded = payrollEmployee($this->tenant, $attributes);
    payrollProfile($this->tenant, $excluded, $profile);

    $lines = collect(stClaim(stCase(), [stHours($this->employee->id), stHours($excluded->id, 0)])->assertCreated()->json('data.lines'))->keyBy('employee_id');

    expect($lines[$excluded->id]['is_eligible'])->toBeFalse()
        ->and($lines[$excluded->id]['ineligibility_reason'])->toContain($reason)
        ->and($lines[$excluded->id]['compensation'])->toBe('0.00')
        ->and($lines[$this->employee->id]['is_eligible'])->toBeTrue();
})->with([
    'notice given' => [['termination_date' => '2026-06-30'], [], 'disdetto'],
    'fixed term' => [[], ['is_fixed_term_contract' => true], 'determinato'],
    'apprentice' => [['employment_type' => 'apprentice'], [], 'Apprendista'],
    'AHV reference age' => [['birth_date' => '1960-01-01'], ['gender' => 'M'], 'riferimento'],
]);

test('an employee who refuses is paid by contract and one without any hours cannot be claimed', function (): void {
    $withoutRecords = payrollEmployee($this->tenant);
    $lines = collect(stClaim(stCase(), [
        stHours($this->employee->id),
        [...stHours($this->colleague->id), 'consented' => false],
        ['employee_id' => $withoutRecords->id],
    ])->assertCreated()->json('data.lines'))->keyBy('employee_id');

    expect($lines[$this->colleague->id]['ineligibility_reason'])->toContain('consenziente')
        ->and($lines[$withoutRecords->id]['ineligibility_reason'])->toContain('controllo');
});

test('hours can come from the working time records', function (): void {
    $user = payrollMember($this->tenant);
    $worker = payrollEmployee($this->tenant, ['user_id' => $user->id, 'base_annual_salary' => '72000.00']);
    payrollProfile($this->tenant, $worker);

    foreach (['2026-03-02', '2026-03-03'] as $date) {
        TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'occurred_at' => "{$date} 08:00:00", 'event_type' => 'In']);
        TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'occurred_at' => "{$date} 16:24:00", 'event_type' => 'Out']);
    }

    $line = stClaim(stCase(), [['employee_id' => $worker->id]])->assertCreated()->json('data.lines.0');

    // March 2026 has 22 weekdays, one of them the Ticino holiday of 19 March: 21 × 8.4 hours.
    expect($line['worked_hours'])->toBe('16.80')->and($line['target_hours'])->toBe('176.40')->and($line['is_eligible'])->toBeTrue();
});

test('late notification, the maximum of periods and a high loss are reported', function (): void {
    $case = stCase(['notified_on' => '2026-02-25']);
    $claim = stClaim($case, [stHours($this->employee->id, 10)])->assertCreated()->json('data');

    expect(implode(' ', $claim['warnings']))->toContain('Preannuncio tardivo')->toContain('85');

    stApi()->putJson('/api/v1/payroll/legal-parameters/short_time_work/2026', ['source' => 'Prova', 'parameters' => [
        'compensation_percent' => '80', 'waiting_days_per_period' => '1', 'maximum_periods' => 1, 'claim_deadline_months' => 3, 'notice_days' => 10, 'urgent_notice_days' => 3, 'maximum_insured_monthly_salary' => '12350',
    ]])->assertOk();

    $next = stClaim($case, [stHours($this->employee->id, 100)], 4)->assertCreated()->json('data');
    expect(implode(' ', $next['warnings']))->toContain('oltre il massimo di 1');
});

test('bad weather refuses to calculate while its waiting days are unverified, then counts with short time', function (): void {
    $case = stCase(['kind' => 'bad_weather']);
    stClaim($case, [stHours($this->employee->id)])->assertUnprocessable();

    stApi()->putJson('/api/v1/payroll/legal-parameters/bad_weather/2026', ['source' => 'Prova azienda', 'parameters' => [
        'compensation_percent' => '80', 'waiting_days_per_period' => '2', 'maximum_periods' => 6, 'claim_deadline_months' => 3, 'maximum_insured_monthly_salary' => '12350',
    ]])->assertOk();

    $claim = stClaim($case, [stHours($this->employee->id)])->assertCreated()->json('data');
    expect($claim['lines'][0]['waiting_hours'])->toBe('16.80')->and($claim['meets_minimum_loss'])->toBeTrue();
});

test('applying a claim lowers the wage, adds the compensation and leaves the social bases on the full salary', function (): void {
    $claim = stClaim(stCase(), [stHours($this->employee->id)])->assertCreated()->json('data.id');
    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim}/submit")->assertOk();
    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim}/apply")->assertOk();
    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim}/apply")->assertUnprocessable();

    $inputs = PayrollInput::query()->withoutGlobalScopes()->orderBy('wage_type_code')->get();
    expect($inputs->pluck('wage_type_code')->all())->toBe(['1910', '2030'])
        ->and((string) $inputs[0]->amount)->toBe('-1077.27');

    payrollCalculate($this->tenant, $this->manager, 2026, 3)->assertCreated();
    $slip = Payslip::query()->withoutGlobalScopes()->where('employee_id', $this->employee->id)->where('month', 3)->sole();

    expect((string) $slip->ahv_basis)->toBe('6000.00')
        ->and((string) $slip->gross_salary)->toBe('5784.55');
});

test('a draft claim does not reach the payroll, an applied claim is not recalculated and a stale copy is refused under the lock', function (): void {
    $case = stCase();
    $claim = stClaim($case, [stHours($this->employee->id)])->assertCreated()->json('data.id');

    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim}/apply")->assertUnprocessable();
    expect(PayrollInput::query()->withoutGlobalScopes()->count())->toBe(0);

    stApi()->postJson("/api/v1/payroll/short-time/claims/{$claim}/submit")->assertOk();
    app(TenantContext::class)->setTenant($this->tenant);
    $stale = ShortTimeClaim::query()->findOrFail($claim);
    $service = app(ShortTimeClaimService::class);

    $locks = RowLocks::takenBy(fn () => $service->applyToPayroll(ShortTimeClaim::query()->findOrFail($claim)));

    expect($locks)->toContain('payroll_short_time_claims')
        ->and(fn () => $service->applyToPayroll($stale))->toThrow(PayrollException::class)
        ->and(PayrollInput::query()->withoutGlobalScopes()->count())->toBe(2);

    ShortTimeClaim::query()->whereKey($claim)->update(['status' => ShortTimeClaim::StatusDraft]);
    stClaim($case, [stHours($this->employee->id, 100)])->assertUnprocessable();
});

test('a claim follows its states to the reimbursement and stops changing once sent', function (): void {
    $case = stCase();
    $claim = stClaim($case, [stHours($this->employee->id)])->assertCreated()->json('data.id');
    $api = stApi();

    $api->postJson("/api/v1/payroll/short-time/claims/{$claim}/reimbursement", ['amount' => '861.82'])->assertUnprocessable();
    $api->postJson("/api/v1/payroll/short-time/claims/{$claim}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    stClaim($case, [stHours($this->employee->id, 100)])->assertUnprocessable();
    $api->postJson("/api/v1/payroll/short-time/claims/{$claim}/reimbursement", ['amount' => '861.82', 'paid_on' => '2026-05-10'])->assertOk()->assertJsonPath('data.status', 'reimbursed');
    expect(ShortTimeClaim::query()->withoutGlobalScopes()->findOrFail($claim)->reimbursed_amount)->toBe('861.82');
});

test('the export is labelled as data for the forms and carries the deadline', function (): void {
    $claim = stClaim(stCase(), [stHours($this->employee->id)])->assertCreated()->json('data.id');
    $file = stApi()->get("/api/v1/payroll/short-time/claims/{$claim}/export")->assertOk();

    expect($file->getContent())->toContain('861.82')
        ->and(rawurldecode((string) $file->headers->get('X-Filing-Summary')))->toContain('Non è il modulo ufficiale')->toContain('30.06.2026');
});

test('cases and claims stay inside the company, foreign data are refused and rights are checked', function (): void {
    $case = stCase();
    $claim = stClaim($case, [stHours($this->employee->id)])->assertCreated()->json('data.id');
    $other = payrollApi($this->otherManager, $this->otherTenant);

    $other->getJson("/api/v1/payroll/short-time/claims/{$claim}")->assertNotFound();
    $other->postJson("/api/v1/payroll/short-time/cases/{$case}/claims", ['year' => 2026, 'month' => 3, 'employees' => [stHours(payrollEmployee($this->otherTenant)->id)]])->assertNotFound();
    expect($other->getJson('/api/v1/payroll/short-time/cases')->assertOk()->json('data'))->toBe([]);

    $foreign = Department::factory()->create(['tenant_id' => $this->otherTenant->id]);
    stApi()->postJson('/api/v1/payroll/short-time/cases', ['kind' => 'short_time', 'cause' => 'x', 'starts_on' => '2026-03-01', 'department_id' => $foreign->id])->assertUnprocessable();
    stApi()->postJson("/api/v1/payroll/short-time/cases/{$case}/claims", ['year' => 2026, 'month' => 3, 'employees' => [['employee_id' => payrollEmployee($this->otherTenant)->id]]])->assertUnprocessable();

    payrollApi(payrollMember($this->tenant), $this->tenant)->getJson('/api/v1/payroll/short-time/cases')->assertForbidden();
    payrollApi(payrollMember($this->tenant, 'member', ['payroll.read']), $this->tenant)->postJson("/api/v1/payroll/short-time/claims/{$claim}/submit")->assertForbidden();

    $italian = payrollTenant('IT');
    payrollApi(payrollMember($italian, 'owner'), $italian)->postJson('/api/v1/payroll/short-time/cases', ['kind' => 'short_time', 'cause' => 'x', 'starts_on' => '2026-03-01'])->assertUnprocessable();
});

test('construction: parameters per agreement give the constant monthly wage and nothing is preloaded', function (): void {
    $api = stApi();
    $api->getJson("/api/v1/payroll/construction/hours?agreement_code=CNM&accounting_year=2026&employee_id={$this->employee->id}")->assertUnprocessable();

    $api->postJson('/api/v1/payroll/construction/parameters', ['agreement_code' => 'CNM', 'valid_from' => '2026-01-01', 'annual_hours' => 2112, 'accounting_year_start_month' => 5, 'minimum_wages' => ['Q' => ['ROSSA' => ['monthly' => '6000', 'hourly' => '34']]], 'source' => 'Valori di prova del test'])->assertCreated();
    $api->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['hourly_wage' => '35.50', 'collective_agreement_code' => 'CNM', 'contract_level' => 'Q', 'ccl_zone' => 'ROSSA'])->assertOk();

    $data = $api->getJson("/api/v1/payroll/construction/hours?agreement_code=CNM&accounting_year=2026&employee_id={$this->employee->id}")->assertOk()->json('data');

    // 35.50 × 2112 / 12 = 6248.00
    expect($data['constant_monthly_wage'])->toBe('6248.00')
        ->and($data['months'][0])->toMatchArray(['year' => 2026, 'month' => 5])
        ->and($data['months'][11])->toMatchArray(['year' => 2027, 'month' => 4]);
});

test('construction: flexible hours run against the calendar with a running balance', function (): void {
    $user = payrollMember($this->tenant);
    $worker = payrollEmployee($this->tenant, ['user_id' => $user->id]);
    payrollProfile($this->tenant, $worker);
    $api = stApi();
    $api->postJson('/api/v1/payroll/construction/parameters', ['agreement_code' => 'CNM', 'valid_from' => '2026-01-01', 'annual_hours' => 2112, 'accounting_year_start_month' => 5, 'minimum_wages' => [], 'source' => 'Valori di prova del test'])->assertCreated();
    $api->putJson('/api/v1/payroll/construction/calendar', ['agreement_code' => 'CNM', 'months' => [['year' => 2026, 'month' => 5, 'planned_hours' => 8], ['year' => 2026, 'month' => 6, 'planned_hours' => 2]]])->assertOk();
    TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'occurred_at' => '2026-05-04 08:00:00', 'event_type' => 'In']);
    TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'occurred_at' => '2026-05-04 18:00:00', 'event_type' => 'Out']);

    $months = stApi()->getJson("/api/v1/payroll/construction/hours?agreement_code=CNM&accounting_year=2026&employee_id={$worker->id}")->assertOk()->json('data.months');

    expect($months[0])->toMatchArray(['worked_hours' => '10.00', 'balance_hours' => '2.00'])
        ->and($months[1])->toMatchArray(['worked_hours' => '0.00', 'balance_hours' => '0.00']);
});

test('construction: the joint commission report averages pay per qualification from closed runs and flags who is under the minimum', function (): void {
    $user = payrollMember($this->tenant);
    $worker = payrollEmployee($this->tenant, ['user_id' => $user->id, 'base_annual_salary' => '36000.00']);
    payrollProfile($this->tenant, $worker, ['salary_installments_per_year' => 12, 'collective_agreement_code' => 'CNM', 'contract_level' => 'Q', 'ccl_zone' => 'ROSSA']);
    $api = stApi();
    $api->postJson('/api/v1/payroll/construction/parameters', ['agreement_code' => 'CNM', 'valid_from' => '2026-01-01', 'annual_hours' => 2112, 'accounting_year_start_month' => 1, 'minimum_wages' => ['Q' => ['ROSSA' => ['monthly' => '6000', 'hourly' => '34']]], 'source' => 'Valori di prova del test'])->assertCreated();

    foreach (['2026-01-05', '2026-01-06'] as $date) {
        TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'occurred_at' => "{$date} 07:00:00", 'event_type' => 'In']);
        TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'occurred_at' => "{$date} 17:00:00", 'event_type' => 'Out']);
    }

    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    payrollCalculate($this->tenant, $this->manager, 2026, 2)->assertCreated();

    $row = stApi()->getJson('/api/v1/payroll/construction/joint-commission-report?agreement_code=CNM&accounting_year=2026')->assertOk()->json('data.rows.0');

    expect($row)->toMatchArray(['qualification' => 'Q', 'zone' => 'ROSSA', 'headcount' => 1, 'worked_hours' => '20.00', 'gross_salary' => '3000.00', 'average_hourly' => '150.00', 'average_monthly' => '3000.00', 'minimum_hourly' => '34', 'below_minimum' => []]);

    $file = stApi()->get('/api/v1/payroll/exports/joint-commission-report?agreement_code=CNM&accounting_year=2026')->assertOk();
    expect($file->getContent())->toContain('ROSSA');
});
