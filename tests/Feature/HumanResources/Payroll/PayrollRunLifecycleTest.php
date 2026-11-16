<?php

declare(strict_types=1);

use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Contracts\Payroll\PayrollRunServiceInterface;
use Modules\HumanResources\DTOs\Payroll\CreatePayrollRunDTO;
use Modules\HumanResources\Enums\Payroll\PayrollRunStatus;
use Modules\HumanResources\Events\PayrollRunApprovedEvent;
use Modules\HumanResources\Events\PayrollRunClosedEvent;
use Modules\HumanResources\Models\Payroll\PayrollRun;
use Modules\HumanResources\Models\Payroll\Payslip;
use Modules\HumanResources\Models\Payroll\PayslipLine;
use Modules\HumanResources\Models\Payroll\WageType;
use Modules\HumanResources\Models\Payroll\WithholdingTariff;
use Tests\Feature\HumanResources\Payroll\PayrollFixtures;

uses(DatabaseTransactions::class);

test('a draft run is calculated, approved and closed with persisted payslips and emitted events', function (): void {
    Event::fake([PayrollRunApprovedEvent::class, PayrollRunClosedEvent::class]);

    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    $employee = PayrollFixtures::employee($tenant);
    Sanctum::actingAs($user, ['*']);

    $create = $this->postJson('/api/v1/hr/payroll/runs', [
        'period_year' => 2026,
        'period_month' => 1,
        'payment_date' => '2026-01-25',
    ]);
    $create->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.period', '2026-01');
    $runId = $create->json('data.id');

    $this->postJson("/api/v1/hr/payroll/runs/{$runId}/calculate")
        ->assertOk()
        ->assertJsonPath('data.status', 'calculated')
        ->assertJsonPath('data.employee_count', 1)
        ->assertJsonPath('data.totals.gross', '8000.00')
        ->assertJsonPath('data.totals.employee_deductions', '915.75')
        ->assertJsonPath('data.totals.employer_contributions', '921.75')
        ->assertJsonPath('data.totals.net', '7084.25');

    $payslips = $this->getJson("/api/v1/hr/payroll/runs/{$runId}/payslips")->assertOk();

    expect($payslips->json('data'))->toHaveCount(1)
        ->and($payslips->json('data.0.employee_id'))->toBe((string) $employee->id)
        ->and($payslips->json('data.0.net'))->toBe('7084.25')
        ->and(collect($payslips->json('data.0.lines'))->firstWhere('code', 'AHV_IV_EO')['amount'])->toBe('424.00');

    $payslipId = $payslips->json('data.0.id');
    $this->getJson("/api/v1/hr/payroll/payslips/{$payslipId}")
        ->assertOk()
        ->assertJsonPath('data.gross', '8000.00');

    $this->postJson("/api/v1/hr/payroll/runs/{$runId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/hr/payroll/runs/{$runId}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    Event::assertDispatched(PayrollRunApprovedEvent::class, fn (PayrollRunApprovedEvent $event): bool => $event->payrollRunId === $runId
        && $event->netRappen === 708_425
        && $event->tenantId === (string) $tenant->id);
    Event::assertDispatched(PayrollRunClosedEvent::class, fn (PayrollRunClosedEvent $event): bool => $event->grossRappen === 800_000
        && $event->employerContributionsRappen === 92_175
        && $event->periodCode === '2026-01');

    expect(PayrollRun::query()->find($runId)?->closed_at)->not->toBeNull();
});

test('approving and closing a run records the domain events in the transactional outbox', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    PayrollFixtures::employee($tenant);

    $service = app(PayrollRunServiceInterface::class);
    $run = $service->createRun(new CreatePayrollRunDTO((string) $tenant->id, (string) $tenant->id, 2026, 2));
    $run = $service->close($service->approve($service->calculate($run)));

    $outbox = TransactionalOutbox::query()
        ->where('aggregate_id', $run->id)
        ->whereIn('event_type', ['hr.payroll.run.approved', 'hr.payroll.run.closed'])
        ->get();

    expect($outbox)->toHaveCount(2)
        ->and($outbox->firstWhere('event_type', 'hr.payroll.run.closed')?->payload_json['netRappen'])->toBe(708_425);
});

test('a closed run cannot be recalculated, edited, approved or closed again', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    $run = PayrollRun::factory()->forPeriod(2026, 1)->closed()->create(['tenant_id' => $tenant->id]);
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/calculate")
        ->assertStatus(409)
        ->assertJsonPath('title', 'Payroll Run State Conflict');
    $this->putJson("/api/v1/hr/payroll/runs/{$run->id}", ['notes' => 'late correction'])->assertStatus(409);
    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/approve")->assertStatus(409);
    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/close")->assertStatus(409);

    expect($run->refresh()->status)->toBe(PayrollRunStatus::Closed)
        ->and($run->notes)->toBeNull();
});

test('a draft run cannot be approved before it is calculated', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    $run = PayrollRun::factory()->forPeriod(2026, 1)->create(['tenant_id' => $tenant->id]);
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/approve")->assertStatus(409);
    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/close")->assertStatus(409);
});

test('calculation fails with 422 and persists nothing when no rate set is valid for the period', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant, 2026);
    PayrollFixtures::employee($tenant);
    $run = PayrollRun::factory()->forPeriod(2031, 1)->create(['tenant_id' => $tenant->id]);
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/calculate");

    $response->assertStatus(422)
        ->assertJsonPath('title', 'No rate set for period')
        ->assertJsonPath('status', 422);
    expect($response->json('invalid_params'))->toHaveKey('period')
        ->and($run->refresh()->status)->toBe(PayrollRunStatus::Draft)
        ->and(Payslip::query()->where('payroll_run_id', $run->id)->count())->toBe(0);
});

test('calculation fails with 422 when the withholding tax tariff is missing for canton, code and date', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    $employee = PayrollFixtures::employee($tenant, profile: [
        'work_canton' => 'TI',
        'permit_type' => 'B',
        'qst_tariff_code' => 'A0N',
        'qst_canton' => 'TI',
    ]);
    $run = PayrollRun::factory()->forPeriod(2026, 1)->create(['tenant_id' => $tenant->id]);
    Sanctum::actingAs($user, ['*']);

    $response = $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/calculate");

    $response->assertStatus(422)->assertJsonPath('title', 'Missing Withholding Tax Tariff');
    expect($response->json('invalid_params'))->toHaveKey("employees.{$employee->id}.qst_tariff_code")
        ->and(Payslip::query()->where('payroll_run_id', $run->id)->count())->toBe(0);
});

test('withholding tax uses the cantonal tariff valid on the period date and honors the church tax variant', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    PayrollFixtures::employee($tenant, profile: [
        'work_canton' => 'TI',
        'permit_type' => 'B',
        'qst_tariff_code' => 'A0N',
        'qst_canton' => 'TI',
        'church_tax' => true,
    ]);

    WithholdingTariff::factory()->flatRate('8.00')->create(['tenant_id' => $tenant->id, 'canton' => 'TI', 'tariff_code' => 'A0N', 'valid_from' => '2026-01-01']);
    WithholdingTariff::factory()->flatRate('10.00')->create(['tenant_id' => $tenant->id, 'canton' => 'TI', 'tariff_code' => 'A0Y', 'valid_from' => '2026-01-01']);
    WithholdingTariff::factory()->flatRate('99.00')->create(['tenant_id' => $tenant->id, 'canton' => 'TI', 'tariff_code' => 'A0Y', 'valid_from' => '2027-01-01']);

    $run = PayrollRun::factory()->forPeriod(2026, 1)->create(['tenant_id' => $tenant->id]);
    Sanctum::actingAs($user, ['*']);

    $this->postJson("/api/v1/hr/payroll/runs/{$run->id}/calculate")
        ->assertOk()
        ->assertJsonPath('data.totals.withholding_tax', '800.00');

    $payslip = Payslip::query()->where('payroll_run_id', $run->id)->firstOrFail();

    expect($payslip->qst_tariff_code)->toBe('A0Y')
        ->and($payslip->qst_canton)->toBe('TI')
        ->and($payslip->withholding_tax_rappen)->toBe(80_000)
        ->and($payslip->net_rappen)->toBe(800_000 - $payslip->employee_deductions_rappen);
});

test('variable inputs add wage type lines and unknown wage types are rejected', function (): void {
    [$tenant, $user] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    $employee = PayrollFixtures::employee($tenant);
    WageType::factory()->create(['tenant_id' => $tenant->id, 'code' => '1200', 'name' => 'Bonus', 'category' => 'allowance']);
    Sanctum::actingAs($user, ['*']);

    $this->postJson('/api/v1/hr/payroll/runs', [
        'period_year' => 2026,
        'period_month' => 3,
        'inputs' => [['employee_id' => $employee->id, 'wage_type_code' => '9999', 'amount' => '100.00']],
    ])->assertStatus(422);

    $runId = $this->postJson('/api/v1/hr/payroll/runs', [
        'period_year' => 2026,
        'period_month' => 3,
        'inputs' => [['employee_id' => $employee->id, 'wage_type_code' => '1200', 'amount' => '1000.00', 'label' => 'Q1 bonus']],
    ])->assertCreated()->json('data.id');

    $this->postJson("/api/v1/hr/payroll/runs/{$runId}/calculate")
        ->assertOk()
        ->assertJsonPath('data.totals.gross', '9000.00');

    // Editing a calculated run discards the payslips and returns it to draft.
    $this->putJson("/api/v1/hr/payroll/runs/{$runId}", ['inputs' => []])
        ->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.totals.gross', '0.00');

    expect(Payslip::query()->where('payroll_run_id', $runId)->count())->toBe(0);
});

test('a new rate set for a new year needs no code change and leaves closed runs untouched', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    $rateSet2026 = PayrollFixtures::referenceData($tenant, 2026);
    $rateSet2027 = PayrollFixtures::referenceData($tenant, 2027, [
        'ahv_iv_eo.employee' => '5.50',
        'ahv_iv_eo.employer' => '5.50',
    ]);
    PayrollFixtures::employee($tenant);

    $service = app(PayrollRunServiceInterface::class);
    $december = $service->createRun(new CreatePayrollRunDTO((string) $tenant->id, (string) $tenant->id, 2026, 12));
    $december = $service->close($service->approve($service->calculate($december)));
    $decemberLinesBefore = PayslipLine::query()
        ->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $december->id)->pluck('id'))
        ->orderBy('sort_order')
        ->get(['code', 'side', 'amount_rappen', 'rate_ppm'])
        ->toArray();

    $january = $service->calculate($service->createRun(new CreatePayrollRunDTO((string) $tenant->id, (string) $tenant->id, 2027, 1)));

    $januaryAhv = PayslipLine::query()
        ->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $january->id)->pluck('id'))
        ->where('code', 'AHV_IV_EO')
        ->where('side', 'employee')
        ->firstOrFail();

    expect($january->rate_set_id)->toBe((string) $rateSet2027->id)
        ->and($januaryAhv->rate_ppm)->toBe(55_000)
        ->and($januaryAhv->amount_rappen)->toBe(44_000)
        ->and($december->refresh()->rate_set_id)->toBe((string) $rateSet2026->id)
        ->and($december->status)->toBe(PayrollRunStatus::Closed)
        ->and(PayslipLine::query()
            ->whereIn('payslip_id', Payslip::query()->where('payroll_run_id', $december->id)->pluck('id'))
            ->orderBy('sort_order')
            ->get(['code', 'side', 'amount_rappen', 'rate_ppm'])
            ->toArray())->toBe($decemberLinesBefore);
});

test('mid-month entries and terminated employees are handled by the run', function (): void {
    [$tenant] = PayrollFixtures::tenantWithUser();
    PayrollFixtures::referenceData($tenant);
    $newcomer = PayrollFixtures::employee(
        $tenant,
        employee: ['hire_date' => '2026-01-16'],
        contract: ['start_date' => '2026-01-16', 'fte_percentage' => 60.00],
    );
    PayrollFixtures::employee($tenant, employee: ['termination_date' => '2025-12-31'], contract: ['end_date' => '2025-12-31']);

    $service = app(PayrollRunServiceInterface::class);
    $run = $service->calculate($service->createRun(new CreatePayrollRunDTO((string) $tenant->id, (string) $tenant->id, 2026, 1)));

    $payslip = Payslip::query()->where('payroll_run_id', $run->id)->sole();

    expect($run->employee_count)->toBe(1)
        ->and($payslip->employee_id)->toBe((string) $newcomer->id)
        ->and($payslip->days_worked)->toBe(15)
        ->and($payslip->gross_rappen)->toBe(240_000);
});
