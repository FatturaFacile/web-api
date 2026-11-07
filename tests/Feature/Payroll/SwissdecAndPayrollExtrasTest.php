<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\HumanResources\Models\BusinessUnit;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\Payroll\Calculation\Dec;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Mail\PayslipAvailableMail;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipDelivery;
use Modules\Payroll\Services\Extras\PayrollPaymentFileService;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;
use Tests\Support\RowLocks;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    Mail::fake();
    $this->tenant = payrollTenant();
    $this->otherTenant = payrollTenant();
    $this->tenant->update(['vat_number' => 'CHE-123.456.789']);
    $this->manager = payrollMember($this->tenant, 'owner');
    $this->otherManager = payrollMember($this->otherTenant, 'owner');
    payrollSwissSetup($this->tenant, $this->manager);
    $this->department = Department::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Produzione']);
    $this->worker = payrollMember($this->tenant);
    $this->employee = payrollEmployee($this->tenant, ['department_id' => $this->department->id, 'user_id' => $this->worker->id, 'work_email' => 'mario@example.ch']);
    payrollProfile($this->tenant, $this->employee, ['social_security_number' => '756.9217.0769.85', 'iban' => 'CH9300762011623852957', 'civil_status' => 'single', 'street' => 'Via Nassa', 'city' => 'Lugano', 'salary_installments_per_year' => 12]);
});

function extrasApi(): mixed
{
    return payrollApi(test()->manager, test()->tenant);
}

function extrasBank(string $tenantId, string $iban, string $currency = 'CHF'): BankAccount
{
    return BankAccount::factory()->create(['tenant_id' => $tenantId, 'iban' => $iban, 'currency' => $currency]);
}

test('the declaration follows the ELM element names, totals the closed runs and states it is not certified', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    payrollClosedRun($this->tenant, $this->manager, 2026, 2);
    payrollCalculate($this->tenant, $this->manager, 2026, 3)->assertCreated();

    $data = extrasApi()->getJson('/api/v1/payroll/swissdec/declaration?year=2026')->assertOk()->json('data');
    $company = $data['SalaryDeclaration']['Company'];
    $salary = $company['Person'][0]['AHV-AVS-Salaries']['AHV-AVS-Salary'];

    expect($data['certification']['is_certified'])->toBeFalse()
        ->and($company['CompanyDescription']['UID-BFS']['UID'])->toBe('CHE-123.456.789')
        ->and($company['Person'][0]['Particulars']['Social-InsuranceIdentification']['SV-AS-Number'])->toBe('756.9217.0769.85')
        ->and($salary['AccountingTime'])->toBe(['from' => '2026-01-01', 'until' => '2026-02-28'])
        ->and($company['SalaryTotals']['AHV-AVS-Totals']['Total-AHV-AVS-Incomes'])->toBe($salary['AHV-AVS-Income'])
        ->and($company['SalaryCounters']['NumberOf-AHV-AVS-Salary-Tags'])->toBe(1);
});

test('readiness lists the gaps of the company and the people, and transmission answers 501 and sends nothing', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    $api = extrasApi();

    $ready = $api->getJson('/api/v1/payroll/swissdec/readiness?year=2026')->assertOk()->json('data');
    expect($ready['company'])->toBe(['Numero di affiliato AVS mancante.'])->and($ready['persons'])->toBe([]);

    $api->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['social_security_number' => '756.1234.5678.90', 'civil_status' => null])->assertOk();
    $issues = extrasApi()->getJson('/api/v1/payroll/swissdec/readiness?year=2026')->assertOk()->json('data.persons.0.issues');
    expect($issues)->toContain('Numero AVS non valido.')->toContain('Stato civile mancante.');

    $response = extrasApi()->postJson('/api/v1/payroll/swissdec/transmissions', ['year' => 2026])->assertStatus(501);
    expect(json_encode($response->json()))->toContain('certificata');
    expect(extrasApi()->getJson('/api/v1/payroll/swissdec/status')->assertOk()->json('data.is_certified'))->toBeFalse();
});

test('Swissdec routes respect company, country and rights', function (): void {
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    payrollApi($this->otherManager, $this->otherTenant)->getJson('/api/v1/payroll/swissdec/declaration?year=2026')->assertUnprocessable();
    payrollApi($this->worker, $this->tenant)->getJson('/api/v1/payroll/swissdec/declaration?year=2026')->assertForbidden();
    payrollApi($this->worker, $this->tenant)->postJson('/api/v1/payroll/swissdec/transmissions', ['year' => 2026])->assertForbidden();

    $italian = payrollTenant('IT');
    payrollApi(payrollMember($italian, 'owner'), $italian)->getJson('/api/v1/payroll/swissdec/declaration?year=2026')->assertUnprocessable();
});

test('gross to net matches the preview, and net to gross finds the salary back', function (): void {
    $forward = extrasApi()->postJson('/api/v1/payroll/hiring-simulations', ['year' => 2026, 'annual_gross' => 84000, 'date_of_birth' => '1990-05-10'])->assertOk()->json('data');
    expect($forward['direction'])->toBe('gross_to_net')->and($forward['monthly_gross'])->toBe('7000.00')->and(bccomp($forward['monthly_net'], '7000', 2))->toBe(-1);

    $back = extrasApi()->postJson('/api/v1/payroll/hiring-simulations', ['year' => 2026, 'target_net_monthly' => $forward['monthly_net'], 'date_of_birth' => '1990-05-10'])->assertOk()->json('data');

    expect($back['direction'])->toBe('net_to_gross')->and($back['is_exact'])->toBeTrue()
        ->and(abs((float) $back['monthly_net'] - (float) $forward['monthly_net']))->toBeLessThanOrEqual(0.05)
        ->and(abs((float) $back['annual_gross'] - 84000))->toBeLessThan(2.0);
});

test('a net that no salary reaches comes back flagged, the simulation saves nothing and needs read access', function (): void {
    $before = DB::table('payroll_payslips')->count();

    $around = extrasApi()->postJson('/api/v1/payroll/hiring-simulations', ['year' => 2026, 'target_net_monthly' => 1200, 'date_of_birth' => '1980-01-01'])->assertOk()->json('data');
    expect($around)->toHaveKeys(['is_exact', 'annual_gross', 'notes'])
        ->and(DB::table('payroll_payslips')->count())->toBe($before);

    extrasApi()->postJson('/api/v1/payroll/hiring-simulations', ['year' => 2026])->assertUnprocessable();
    extrasApi()->postJson('/api/v1/payroll/hiring-simulations', ['year' => 2026, 'annual_gross' => 1, 'target_net_monthly' => 1])->assertUnprocessable();
    payrollApi($this->worker, $this->tenant)->postJson('/api/v1/payroll/hiring-simulations', ['year' => 2026, 'annual_gross' => 84000])->assertForbidden();
});

test('payslip mails need a closed run and carry no figure, and the link stores only a hash', function (): void {
    $run = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    extrasApi()->postJson("/api/v1/payroll/runs/{$run}/deliver-payslips")->assertUnprocessable();
    extrasApi()->postJson("/api/v1/payroll/runs/{$run}/finalize")->assertOk();

    $outcome = extrasApi()->postJson("/api/v1/payroll/runs/{$run}/deliver-payslips")->assertOk()->json('data');
    expect($outcome)->toBe(['sent' => 1, 'skipped' => []]);

    Mail::assertQueued(PayslipAvailableMail::class, function (PayslipAvailableMail $mail): bool {
        $body = $mail->render();
        $amount = (string) Payslip::query()->withoutGlobalScopes()->sole()->payout_amount;

        return $mail->hasTo('mario@example.ch') && ! str_contains($body, $amount) && $mail->attachments === [];
    });

    $mail = Mail::queued(PayslipAvailableMail::class)->first();
    $token = Str::afterLast($mail->link, '/');
    expect(PayslipDelivery::query()->withoutGlobalScopes()->sole()->token_hash)->toBe(hash('sha256', $token))->not->toBe($token);
});

test('the link opens with the date of birth, locks after wrong answers and expires', function (): void {
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/deliver-payslips")->assertOk();
    $token = Str::afterLast(Mail::queued(PayslipAvailableMail::class)->first()->link, '/');
    app(TenantContext::class)->clear();

    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '1986-05-10'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(PayslipDelivery::query()->withoutGlobalScopes()->sole()->opened_at)->not->toBeNull();

    $this->postJson('/api/v1/portal/payslips/inesistente', ['birth_date' => '1986-05-10'])->assertNotFound();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '2000-01-01'])->assertNotFound();
    }

    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '1986-05-10'])->assertNotFound();

    PayslipDelivery::query()->withoutGlobalScopes()->update(['failed_attempts' => 0, 'expires_at' => now()->subMinute()]);
    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '1986-05-10'])->assertNotFound();
});

test('sending the payslips again revokes the earlier links and only the newest one opens', function (): void {
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/deliver-payslips")->assertOk();
    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/deliver-payslips")->assertOk();

    [$first, $second] = Mail::queued(PayslipAvailableMail::class)->map(static fn (PayslipAvailableMail $mail): string => Str::afterLast($mail->link, '/'))->values()->all();
    $deliveries = PayslipDelivery::query()->withoutGlobalScopes()->orderBy('created_at')->get();
    app(TenantContext::class)->clear();

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries->whereNotNull('revoked_at'))->toHaveCount(1)
        ->and($deliveries->firstWhere('token_hash', hash('sha256', $first))->revoked_at)->not->toBeNull()
        ->and($deliveries->firstWhere('token_hash', hash('sha256', $second))->revoked_at)->toBeNull();

    $this->postJson("/api/v1/portal/payslips/{$first}", ['birth_date' => '1986-05-10'])->assertNotFound();
    $this->postJson("/api/v1/portal/payslips/{$second}", ['birth_date' => '1986-05-10'])->assertOk();
});

test('during a restore the payslip link answers 503 and records nothing', function (): void {
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/deliver-payslips")->assertOk();
    $token = Str::afterLast(Mail::queued(PayslipAvailableMail::class)->first()->link, '/');
    $this->tenant->forceFill(['is_maintenance' => true])->save();
    app(TenantContext::class)->clear();

    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '1986-05-10'])->assertStatus(503)->assertHeader('Retry-After', '60');

    $delivery = PayslipDelivery::query()->withoutGlobalScopes()->sole();
    expect($delivery->failed_attempts)->toBe(0)->and($delivery->opened_at)->toBeNull();
});

test('attempts that other requests used up after this one read the link are counted in the same UPDATE', function (): void {
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/deliver-payslips")->assertOk();
    $token = Str::afterLast(Mail::queued(PayslipAvailableMail::class)->first()->link, '/');
    app(TenantContext::class)->clear();

    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '2000-01-01'])->assertNotFound();
    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '1986-05-10'])->assertOk();
    expect(PayslipDelivery::query()->withoutGlobalScopes()->sole()->failed_attempts)->toBe(1);

    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if (! $raced && str_starts_with($query->sql, 'select * from "payroll_payslips"')) {
            $raced = true;
            DB::table('payroll_payslip_deliveries')->update(['failed_attempts' => 5]);
        }
    });

    $this->postJson("/api/v1/portal/payslips/{$token}", ['birth_date' => '1986-05-10'])->assertNotFound();
    expect($raced)->toBeTrue()->and(PayslipDelivery::query()->withoutGlobalScopes()->sole()->failed_attempts)->toBe(5);
});

test('someone without a date of birth or a mail address is skipped with the reason', function (): void {
    $this->employee->update(['birth_date' => null]);
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    $outcome = extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/deliver-payslips")->assertOk()->json('data');

    expect($outcome['sent'])->toBe(0)->and($outcome['skipped'][0]['reason'])->toContain('Data di nascita');
    Mail::assertNothingQueued();
});

test('a closed run becomes one Treasury batch per bank, which needs a second approver', function (): void {
    $production = extrasBank($this->tenant->id, 'CH5604835012345678009');
    $office = extrasBank($this->tenant->id, 'CH4431999123000889012');
    $colleague = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $colleague, ['iban' => 'CH5604835099999999999', 'salary_installments_per_year' => 12]);
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    $batches = extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/payment-batches", ['allocations' => [
        ['bank_account_id' => $production->id, 'department_id' => $this->department->id],
        ['bank_account_id' => $office->id],
    ]])->assertCreated()->json('data');

    expect($batches)->toHaveCount(2)
        ->and(array_column($batches, 'item_count'))->toBe([1, 1])
        ->and(array_column($batches, 'status'))->toBe(['draft', 'draft']);

    $line = PaymentLine::query()->withoutGlobalScopes()->where('payment_batch_id', collect($batches)->firstWhere('bank_account_id', $production->id)['id'])->sole();
    expect($line->recipient_iban)->toBe('CH9300762011623852957')->and($line->recipient_type)->toBe('employee')
        ->and((string) $line->amount)->toBe((string) Payslip::query()->withoutGlobalScopes()->where('employee_id', $this->employee->id)->sole()->payout_amount);

    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/payment-batches", ['allocations' => [['bank_account_id' => $office->id]]])->assertUnprocessable();
});

test('the payment file locks the run before it checks for an existing batch, so a second request is refused', function (): void {
    $account = extrasBank($this->tenant->id, 'CH5604835012345678009');
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    app(TenantContext::class)->setTenant($this->tenant);
    $service = app(PayrollPaymentFileService::class);

    $locks = RowLocks::takenBy(fn () => $service->createBatches($run, $this->manager, 'CHF', [['bank_account_id' => $account->id]]));

    expect($locks[0] ?? null)->toBe('payroll_runs')
        ->and(fn () => $service->createBatches($run, $this->manager, 'CHF', [['bank_account_id' => $account->id]]))->toThrow(PayrollException::class)
        ->and(PaymentBatch::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a payment file needs a closed run, every IBAN and the right currency', function (): void {
    $account = extrasBank($this->tenant->id, 'CH5604835012345678009');
    $euro = extrasBank($this->tenant->id, 'CH4431999123000889012', 'EUR');
    $id = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    extrasApi()->postJson("/api/v1/payroll/runs/{$id}/payment-batches", ['allocations' => [['bank_account_id' => $account->id]]])->assertUnprocessable();
    extrasApi()->postJson("/api/v1/payroll/runs/{$id}/finalize")->assertOk();

    extrasApi()->postJson("/api/v1/payroll/runs/{$id}/payment-batches", ['allocations' => [['bank_account_id' => $euro->id]]])->assertUnprocessable();
    expect(PaymentBatch::query()->withoutGlobalScopes()->count())->toBe(0);

    $noIban = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $noIban, ['iban' => null]);
    $second = payrollClosedRun($this->tenant, $this->manager, 2026, 2);
    $message = extrasApi()->postJson("/api/v1/payroll/runs/{$second->id}/payment-batches", ['allocations' => [['bank_account_id' => $account->id]]])->assertUnprocessable();
    expect(json_encode($message->json()))->toContain('IBAN mancante');
    expect(PaymentBatch::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('a salary IBAN stays out of the audit ledger, while supplier payments stay audited as before', function (): void {
    $account = extrasBank($this->tenant->id, 'CH5604835012345678009');
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/payment-batches", ['allocations' => [['bank_account_id' => $account->id]]])->assertCreated();

    $payloads = AuditLedgerEntry::query()->withoutGlobalScopes()->get()->map(fn ($entry): string => json_encode([$entry->payload_before, $entry->payload_after]))->implode(' ');

    expect($payloads)->not->toContain('CH9300762011623852957')
        ->and((new PaymentLine(['recipient_type' => 'supplier']))->auditPayload(['recipient_iban' => 'CH00']))->toBe(['recipient_iban' => 'CH00'])
        ->and((new PaymentLine(['recipient_type' => 'employee']))->auditPayload(['recipient_iban' => 'CH00', 'amount' => 1]))->toBe(['amount' => 1]);
});

test('payment routes refuse another company', function (): void {
    $account = extrasBank($this->tenant->id, 'CH5604835012345678009');
    $foreignAccount = extrasBank($this->otherTenant->id, 'CH4431999123000889012');
    $run = payrollClosedRun($this->tenant, $this->manager, 2026, 1);

    extrasApi()->postJson("/api/v1/payroll/runs/{$run->id}/payment-batches", ['allocations' => [['bank_account_id' => $foreignAccount->id]]])->assertUnprocessable();
    payrollApi($this->otherManager, $this->otherTenant)->postJson("/api/v1/payroll/runs/{$run->id}/payment-batches", ['allocations' => [['bank_account_id' => $account->id]]])->assertUnprocessable();
    payrollApi($this->worker, $this->tenant)->postJson("/api/v1/payroll/runs/{$run->id}/payment-batches", ['allocations' => [['bank_account_id' => $account->id]]])->assertForbidden();
});

test('labour cost is grouped by department or site with hours and cost per hour, from closed runs only', function (): void {
    $site = BusinessUnit::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Lugano']);
    $this->employee->update(['business_unit_id' => $site->id]);
    TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'occurred_at' => '2026-01-05 08:00:00', 'event_type' => 'In']);
    TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'occurred_at' => '2026-01-05 18:00:00', 'event_type' => 'Out']);
    payrollClosedRun($this->tenant, $this->manager, 2026, 1);
    payrollCalculate($this->tenant, $this->manager, 2026, 2)->assertCreated();

    $slip = Payslip::query()->withoutGlobalScopes()->where('month', 1)->sole();
    $cost = bcadd((string) $slip->gross_salary, (string) $slip->employer_contributions, 2);

    $department = extrasApi()->getJson('/api/v1/payroll/reports/labour-cost?year=2026')->assertOk()->json('data.rows.0');
    expect($department)->toMatchArray(['group_id' => $this->department->id, 'group_name' => 'Produzione', 'headcount' => 1, 'hours' => '10.00', 'labour_cost' => $cost, 'cost_per_hour' => Dec::round(Dec::div($cost, '10'))]);

    expect(extrasApi()->getJson('/api/v1/payroll/reports/labour-cost?year=2026&month=1&group_by=site')->assertOk()->json('data.rows.0.group_name'))->toBe('Lugano');
    payrollApi($this->worker, $this->tenant)->getJson('/api/v1/payroll/reports/labour-cost?year=2026')->assertForbidden();
});

test('each child record shows the allowance the engine would pay for it', function (): void {
    PayrollFamilyAllowanceRate::query()->withoutGlobalScopes()->where('canton', 'TI')->forceDelete();
    DB::table('payroll_family_allowance_rates')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'canton' => 'TI', 'valid_from' => '2026-01-01', 'child_allowance' => '200', 'education_allowance' => '250']);
    $api = extrasApi();
    $api->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['work_canton' => 'TI'])->assertOk();

    foreach ([['Anna', '2020-01-01', false], ['Luca', '2006-01-01', true], ['Sara', '1990-01-01', false]] as [$name, $birth, $education]) {
        $api->postJson('/api/v1/payroll/children', ['employee_id' => $this->employee->id, 'first_name' => $name, 'date_of_birth' => $birth, 'in_education' => $education])->assertCreated();
    }

    $rows = collect($api->getJson("/api/v1/payroll/children/allowances?year=2026&month=3&employee_id={$this->employee->id}")->assertOk()->json('data'));

    expect($rows->pluck('kind')->sort()->values()->all())->toBe([null, 'child', 'education'])
        ->and($rows->firstWhere('kind', 'child')['amount'])->toBe('200.00')
        ->and($rows->firstWhere('kind', 'education')['amount'])->toBe('250.00');

    $id = payrollCalculate($this->tenant, $this->manager, 2026, 3)->assertCreated()->json('data.id');
    $lines = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $id)->sole()->lines()->withoutGlobalScopes()->get()->keyBy('wage_type_code');
    expect((string) $lines['3000']->amount)->toBe('200.00')->and((string) $lines['3010']->amount)->toBe('250.00');
    expect(PayrollRun::query()->withoutGlobalScopes()->count())->toBe(1);
});
