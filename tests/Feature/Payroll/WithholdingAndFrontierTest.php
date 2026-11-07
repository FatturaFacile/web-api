<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\PayrollLegalParameter;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\PayrollWithholdingStatement;
use Modules\Payroll\Services\LegalParameterResolver;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->otherTenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'owner');
    $this->otherManager = payrollMember($this->otherTenant, 'owner');
    payrollSwissSetup($this->tenant, $this->manager);
    $this->employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $this->employee, [
        'social_security_number' => '756.1234.5678.97',
        'is_withholding_tax_subject' => true,
        'withholding_tax_code' => 'A0N',
        'withholding_tax_rate_percent' => '10',
        'residence_country' => 'IT',
        'work_canton' => 'TI',
        'street' => 'Via Roma',
        'house_number' => '1',
        'postal_code' => '22100',
        'city' => 'Como',
    ]);
});

function withholdingApi(): mixed
{
    return payrollApi(test()->manager, test()->tenant);
}

test('a quarterly statement sums the closed months per canton, and a draft month stays out', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    payrollClosedRun($this->tenant, $this->manager, 2026, 2);
    payrollCalculate($this->tenant, $this->manager, 2026, 3)->assertCreated();

    $data = withholdingApi()->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=quarterly&period=1')->assertOk()->json('data');
    $statement = $data['statements'][0];

    expect($data['format_status'])->toContain('unverified')
        ->and($statement['canton'])->toBe('TI')
        ->and($statement['recommended_period'])->toBe('quarterly')
        ->and(array_column($statement['lines'], 'month'))->toBe([1, 2])
        ->and($statement['lines'][0]['tariff_code'])->toBe('A0N')
        ->and(bccomp($statement['total_tax'], bcadd($statement['lines'][0]['tax'], $statement['lines'][1]['tax'], 2), 2))->toBe(0)
        ->and(bccomp($statement['total_tax'], '0', 2))->toBe(1);
});

test('a monthly and an annual statement take the right months and wrong periods are refused', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    payrollClosedRun($this->tenant, $this->manager, 2026, 5);
    $api = withholdingApi();

    expect($api->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=monthly&period=5')->json('data.statements.0.lines'))->toHaveCount(1)
        ->and($api->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=annual&period=1')->json('data.statements.0.lines'))->toHaveCount(2);

    $api->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=quarterly&period=5')->assertUnprocessable();
    $api->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=monthly&period=13')->assertUnprocessable();
});

test('a closed period is kept as declared, encrypted, and a later change becomes a correction', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $api = withholdingApi();
    $closed = $api->postJson('/api/v1/payroll/withholding-statements', ['year' => 2026, 'period_type' => 'monthly', 'period' => 1, 'canton' => 'TI'])->assertCreated();
    expect($closed->json('data'))->not->toHaveKey('content');

    $api->postJson('/api/v1/payroll/withholding-statements', ['year' => 2026, 'period_type' => 'monthly', 'period' => 1, 'canton' => 'TI'])->assertUnprocessable();

    $stored = PayrollWithholdingStatement::query()->withoutGlobalScopes()->sole();
    expect($stored->getRawOriginal('content'))->not->toContain('756.1234.5678.97')
        ->and(fn () => $stored->update(['total_tax' => 1]))->toThrow(LogicException::class);

});

test('after a closed month is recalculated the next statement reverses the old line and adds the new one', function (): void {
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $api = withholdingApi();
    $api->postJson('/api/v1/payroll/withholding-statements', ['year' => 2026, 'period_type' => 'monthly', 'period' => 1, 'canton' => 'TI'])->assertCreated();
    $declared = PayrollWithholdingStatement::query()->withoutGlobalScopes()->sole()->content['lines'][0];

    $api->postJson("/api/v1/payroll/runs/{$run->id}/reopen", ['reason' => 'Correzione del salario'])->assertOk();
    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['withholding_tax_rate_percent' => '12'])->assertOk();
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/runs/{$id}/finalize")->assertOk();
    payrollClosedRun($this->tenant, $this->manager, 2026, 2);

    $statement = withholdingApi()->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=monthly&period=2&canton=TI')->assertOk()->json('data.statements.0');

    expect(array_column($statement['corrections'], 'entry_type'))->toBe(['reversal', 'correction'])
        ->and($statement['corrections'][0]['tax'])->toBe('-'.$declared['tax'])
        ->and(bccomp($statement['corrections'][1]['tax'], $declared['tax'], 2))->toBe(1);
});

test('a statement cannot be closed with missing data and the export says it is no official file', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $this->employee->update(['birth_date' => null]);
    $api = withholdingApi();

    $api->postJson('/api/v1/payroll/withholding-statements', ['year' => 2026, 'period_type' => 'monthly', 'period' => 1, 'canton' => 'TI'])->assertUnprocessable();

    $file = $api->get('/api/v1/payroll/exports/withholding-statement?year=2026&period_type=monthly&period=1&canton=TI')->assertOk();
    expect($file->headers->get('Content-Disposition'))->toContain('formato_non_ufficiale')
        ->and(rawurldecode((string) $file->headers->get('X-Filing-Summary')))->toContain('non è un file ufficiale')
        ->and($file->getContent())->toContain('756.1234.5678.97');
});

test('statements stay inside the company and need payroll rights', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    expect(payrollApi($this->otherManager, $this->otherTenant)->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=monthly&period=1')->assertOk()->json('data.statements'))->toBe([]);

    $member = payrollMember($this->tenant);
    payrollApi($member, $this->tenant)->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=monthly&period=1')->assertForbidden();
    payrollApi($member, $this->tenant)->postJson('/api/v1/payroll/withholding-statements', ['year' => 2026, 'period_type' => 'monthly', 'period' => 1, 'canton' => 'TI'])->assertForbidden();

    $italian = payrollTenant('IT');
    payrollApi(payrollMember($italian, 'owner'), $italian)->getJson('/api/v1/payroll/withholding-statements?year=2026&period_type=monthly&period=1')->assertUnprocessable();
});

/**
 * @param  array<string, mixed>  $values
 */
function frontierWorker(array $values = []): array
{
    withholdingApi()->putJson('/api/v1/payroll/employee-profiles/'.test()->employee->id, [
        'frontier_commune_in_zone' => true,
        'frontier_status_valid_from' => '2021-02-01',
        'withholding_tax_code' => 'A0N',
        'is_withholding_tax_subject' => true,
        ...$values,
    ])->assertOk();

    return withholdingApi()->getJson('/api/v1/payroll/frontier-workers?year=2026')->assertOk()->json('data.workers.0');
}

test('the regime follows the date of the status against the transitional window', function (string $validFrom, string $code, string $regime): void {
    expect(frontierWorker(['frontier_status_valid_from' => $validFrom, 'withholding_tax_code' => $code]))
        ->toMatchArray(['regime' => $regime, 'issues' => []]);
})->with([
    'employed before the window' => ['2015-03-01', 'A0N', 'old'],
    'in the window' => ['2021-02-01', 'B1N', 'old'],
    'last day of the window' => ['2023-07-17', 'H1N', 'old'],
    'day after the window' => ['2023-07-18', 'R0N', 'new'],
    'after first application' => ['2024-03-01', 'S1N', 'new'],
]);

test('a tariff of the wrong regime and the declared regime against the data are reported', function (): void {
    expect(frontierWorker(['frontier_status_valid_from' => '2024-03-01', 'withholding_tax_code' => 'A0N'])['issues'][0])->toContain('Tariffa A');

    $worker = frontierWorker(['frontier_status_valid_from' => '2024-03-01', 'frontier_regime' => 'old', 'withholding_tax_code' => 'A0N']);
    expect($worker['regime'])->toBe('old')->and($worker['suggested_regime'])->toBe('new')->and($worker['issues'][0])->toContain('dai dati risulta new');
});

test('outside the zone, the border cantons or the daily return the worker is not covered', function (array $values): void {
    expect(frontierWorker($values)['regime'])->toBe('not_covered');
})->with([
    'commune outside the list' => [['frontier_commune_in_zone' => false]],
    'canton outside the region' => [['work_canton' => 'ZH']],
    'no daily return' => [['frontier_returns_daily' => false]],
]);

test('more than the allowed nights away lose the status for the year only', function (): void {
    frontierWorker(['frontier_status_valid_from' => '2024-03-01', 'withholding_tax_code' => 'R0N']);
    $api = withholdingApi();

    $api->putJson("/api/v1/payroll/frontier-workers/{$this->employee->id}/nights", ['year' => 2026, 'nights_away' => 45])->assertOk();
    expect($api->getJson('/api/v1/payroll/frontier-workers?year=2026')->json('data.workers.0.regime'))->toBe('new');

    $api->putJson("/api/v1/payroll/frontier-workers/{$this->employee->id}/nights", ['year' => 2026, 'nights_away' => 46])->assertOk();
    $worker = $api->getJson('/api/v1/payroll/frontier-workers?year=2026')->json('data.workers.0');
    expect($worker['regime'])->toBe('not_covered')->and($worker['issues'][0])->toContain('45 notti');
    expect($api->getJson('/api/v1/payroll/frontier-workers?year=2027')->json('data.workers.0.regime'))->toBe('new');
});

test('the parameters of an older year come with a warning and a company row wins', function (): void {
    $api = withholdingApi();
    expect($api->getJson('/api/v1/payroll/frontier-workers?year=2026')->json('data'))->toMatchArray(['parameters_year' => 2024])
        ->and($api->getJson('/api/v1/payroll/frontier-workers?year=2026')->json('data.warnings.0'))->toContain('2024');

    $api->putJson('/api/v1/payroll/legal-parameters/frontier_italy/2024', ['source' => 'Prova azienda', 'parameters' => [
        'border_cantons' => ['GR', 'TI', 'VS', 'ZH'], 'transitional_to' => '2023-07-17', 'old_regime_tariffs' => ['A'], 'new_regime_tariffs' => ['R'], 'maximum_nights_away_per_year' => 45,
    ]])->assertOk();

    expect(frontierWorker(['work_canton' => 'ZH'])['regime'])->toBe('old');

    payrollMember($this->otherTenant);
    expect(collect(payrollApi($this->otherManager, $this->otherTenant)->getJson('/api/v1/payroll/legal-parameters')->assertOk()->json('data'))->where('topic', 'frontier_italy')->pluck('source')->all())->not->toContain('Prova azienda');
    payrollApi($this->otherManager, $this->otherTenant)->putJson('/api/v1/payroll/legal-parameters/nonsense/2024', ['source' => 'xxxxx', 'parameters' => ['a' => 1]])->assertNotFound();
});

test('a generic row cannot be changed by a company and a missing legal value stops the calculation', function (): void {
    $api = withholdingApi();
    $api->putJson('/api/v1/payroll/legal-parameters/short_time_work/2025', ['source' => 'Azienda', 'parameters' => ['compensation_percent' => '100']])->assertOk();

    expect(PayrollLegalParameter::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('topic', 'short_time_work')->sole()->parameters['compensation_percent'])->toBe('80');

    expect(fn () => app(LegalParameterResolver::class)->resolve('bad_weather', 2025)->required('waiting_days_per_period'))
        ->toThrow(PayrollException::class);
});

test('the exchange file holds the new frontier workers and the personal data are encrypted at rest', function (): void {
    frontierWorker(['frontier_status_valid_from' => '2024-03-01', 'withholding_tax_code' => 'R0N', 'italian_tax_code' => 'RSSMRA86E10F205X', 'birth_place' => 'Como']);
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    $file = withholdingApi()->get('/api/v1/payroll/exports/frontier-exchange?year=2026')->assertOk();
    expect($file->getContent())->toContain('RSSMRA86E10F205X')->toContain('Como')
        ->and(rawurldecode((string) $file->headers->get('X-Filing-Summary')))->toContain('non è un formato ufficiale');

    $row = DB::table('payroll_employee_profiles')->where('employee_id', $this->employee->id)->first();
    expect($row->italian_tax_code)->not->toContain('RSSMRA')->and($row->birth_place)->not->toBe('Como');

    frontierWorker(['frontier_status_valid_from' => '2021-02-01', 'withholding_tax_code' => 'A0N']);
    expect(withholdingApi()->get('/api/v1/payroll/exports/frontier-exchange?year=2026')->getContent())->not->toContain('RSSMRA86E10F205X');
});

test('the personal data of a frontier worker are validated', function (): void {
    withholdingApi()->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['italian_tax_code' => 'TROPPOCORTO'])->assertUnprocessable()->assertJsonValidationErrors('italian_tax_code');
    withholdingApi()->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['frontier_regime' => 'altro'])->assertUnprocessable()->assertJsonValidationErrors('frontier_regime');
    expect(PayrollRun::query()->withoutGlobalScopes()->count())->toBe(0);
});
