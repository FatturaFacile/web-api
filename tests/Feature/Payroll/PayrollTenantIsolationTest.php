<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Payroll\Models\CollectiveAgreement;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollFamilyAllowanceRate;
use Modules\Payroll\Models\PayrollWithholdingTaxTariff;
use Modules\Payroll\Models\Payslip;

require_once __DIR__.'/Support.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = payrollTenant();
    $this->otherTenant = payrollTenant();
    $this->manager = payrollMember($this->tenant, 'owner');
    $this->otherManager = payrollMember($this->otherTenant, 'owner');
    $this->employee = payrollEmployee($this->tenant);
    payrollProfile($this->tenant, $this->employee);
    payrollSwissSetup($this->tenant, $this->manager);
    $this->runId = payrollCalculate($this->tenant, $this->manager, 2026, 1)->assertCreated()->json('data.id');
    $this->payslip = Payslip::query()->withoutGlobalScopes()->where('payroll_run_id', $this->runId)->sole();
});

function payrollTariffFile(string $rate): string
{
    return '0601TI'.str_pad('A0N', 10).'20260101000000000000010000 00000000000'.$rate.'   ';
}

test('runs and payslips of another company do not exist', function (): void {
    $api = fn () => payrollApi($this->otherManager, $this->otherTenant);

    $api()->getJson('/api/v1/payroll/runs')->assertOk()->assertJsonCount(0, 'data');
    $api()->getJson('/api/v1/payroll/payslips')->assertOk()->assertJsonCount(0, 'data');
    $api()->getJson("/api/v1/payroll/runs/{$this->runId}")->assertNotFound();
    $api()->postJson("/api/v1/payroll/runs/{$this->runId}/finalize")->assertNotFound();
    $api()->postJson("/api/v1/payroll/runs/{$this->runId}/reopen", ['reason' => 'Correzione'])->assertNotFound();
    $api()->getJson("/api/v1/payroll/payslips/{$this->payslip->id}")->assertNotFound();
    $api()->getJson("/api/v1/payroll/payslips/{$this->payslip->id}/pdf")->assertNotFound();
});

test('a user cannot enter a company it does not belong to', function (): void {
    payrollApi($this->otherManager, $this->tenant)->getJson('/api/v1/payroll/runs')->assertForbidden();
});

test('the employees of another company cannot be referenced', function (): void {
    $api = fn () => payrollApi($this->otherManager, $this->otherTenant);

    $api()->getJson("/api/v1/payroll/employee-profiles/{$this->employee->id}")->assertNotFound();
    $api()->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['work_canton' => 'ZH'])->assertNotFound();
    $api()->postJson('/api/v1/payroll/runs/preview', ['employee_id' => $this->employee->id])->assertNotFound();
    $api()->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 1, 'employee_id' => $this->employee->id])->assertNotFound();
    $api()->postJson('/api/v1/payroll/inputs', ['employee_id' => $this->employee->id, 'year' => 2026, 'month' => 2, 'wage_type_code' => '1100', 'amount' => '10'])->assertNotFound();
    $api()->postJson('/api/v1/payroll/absences', ['employee_id' => $this->employee->id, 'absence_type' => 'Unpaid', 'start_date' => '2026-02-01', 'end_date' => '2026-02-02'])->assertNotFound();
    $api()->postJson('/api/v1/payroll/children', ['employee_id' => $this->employee->id, 'first_name' => 'Luca', 'date_of_birth' => '2020-01-01'])->assertNotFound();
});

test('a calculation never touches the employees of another company', function (): void {
    payrollEmployee($this->otherTenant);
    payrollSwissSetup($this->otherTenant, $this->otherManager);

    payrollCalculate($this->otherTenant, $this->otherManager, 2026, 1)->assertCreated();

    expect(Payslip::query()->withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1)
        ->and(Payslip::query()->withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->pluck('employee_id')->all())->not->toContain($this->employee->id);
});

test('generic rows are visible to every company and read-only', function (): void {
    $rate = PayrollFamilyAllowanceRate::query()->withoutGlobalScopes()->whereNull('tenant_id')->where('canton', 'TI')->sole();
    auth()->forgetGuards();
    $generic = CollectiveAgreement::factory()->generic()->create(['code' => 'GEN', 'name' => 'Generico']);

    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/family-allowance-rates?per_page=200')
        ->assertOk()
        ->assertJsonFragment(['id' => $rate->id, 'is_generic' => true]);

    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/family-allowance-rates/{$rate->id}", [
        'canton' => 'TI', 'valid_from' => '2025-01-01', 'child_allowance' => 300, 'education_allowance' => 300,
    ])->assertForbidden();
    payrollApi($this->manager, $this->tenant)->deleteJson("/api/v1/payroll/family-allowance-rates/{$rate->id}")->assertForbidden();
    payrollApi($this->manager, $this->tenant)->getJson("/api/v1/payroll/collective-agreements/{$generic->id}")->assertOk()->assertJsonPath('data.is_generic', true);
    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/collective-agreements/{$generic->id}", ['code' => 'GEN', 'name' => 'Mio'])->assertForbidden();
    payrollApi($this->manager, $this->tenant)->postJson("/api/v1/payroll/collective-agreements/{$generic->id}/levels", ['level' => '1', 'monthly_minimum' => 1])->assertForbidden();

    expect($rate->refresh()->child_allowance)->toBe('215.00');
});

test('family allowances of a company win over the generic row and stay in the company', function (): void {
    $child = ['employee_id' => $this->employee->id, 'first_name' => 'Luca', 'date_of_birth' => '2020-01-01'];
    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/children', $child)->assertCreated();
    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/family-allowance-rates', [
        'canton' => 'TI', 'valid_from' => '2025-01-01', 'child_allowance' => 300, 'education_allowance' => 350,
    ])->assertCreated();
    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/family-allowance-rates', [
        'canton' => 'TI', 'valid_from' => '2025-01-01', 'child_allowance' => 100, 'education_allowance' => 350,
    ])->assertUnprocessable()->assertJsonValidationErrors('child_allowance');

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 2])
        ->assertOk()
        ->assertJsonPath('data.0.lines.1.wage_type_code', '3000')
        ->assertJsonPath('data.0.lines.1.amount', '300.00');

    expect(PayrollFamilyAllowanceRate::query()->withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(0);
});

test('imported tariffs replace the generic ones of the same date, only in the importing company', function (): void {
    $now = now();
    PayrollWithholdingTaxTariff::query()->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => null, 'canton' => 'TI', 'tariff_code' => 'A0N', 'valid_from' => '2026-01-01',
        'income_from' => '0', 'income_step' => '10000', 'minimum_tax' => '0', 'rate_percent' => '5', 'created_at' => $now, 'updated_at' => $now,
    ]);

    foreach ([[$this->tenant, $this->manager], [$this->otherTenant, $this->otherManager]] as [$tenant, $manager]) {
        $employee = $tenant->is($this->tenant) ? $this->employee : payrollEmployee($tenant);

        if (! $tenant->is($this->tenant)) {
            payrollProfile($tenant, $employee);
            payrollSwissSetup($tenant, $manager);
        }

        payrollApi($manager, $tenant)->putJson("/api/v1/payroll/employee-profiles/{$employee->id}", ['is_withholding_tax_subject' => true, 'withholding_tax_code' => 'A0N'])->assertOk();
    }

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/withholding-tax-tariffs/import', ['content' => payrollTariffFile('00800')])
        ->assertOk()
        ->assertJsonPath('data.imported', 1);

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 1])
        ->assertOk()
        ->assertJsonPath('data.0.withholding_tax_rate_percent', '8.0000');

    payrollApi($this->otherManager, $this->otherTenant)->postJson('/api/v1/payroll/runs/simulate', ['year' => 2026, 'month' => 1])
        ->assertOk()
        ->assertJsonPath('data.0.withholding_tax_rate_percent', '5.0000');

    payrollApi($this->otherManager, $this->otherTenant)->getJson('/api/v1/payroll/withholding-tax-tariffs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.canton', 'TI');
});

test('a tariff file can be uploaded and a file without records is refused', function (): void {
    $file = UploadedFile::fake()->createWithContent('tar26ti.txt', payrollTariffFile('00412'));

    payrollApi($this->manager, $this->tenant)->post('/api/v1/payroll/withholding-tax-tariffs/import', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.imported', 1);

    payrollApi($this->manager, $this->tenant)->postJson('/api/v1/payroll/withholding-tax-tariffs/import', ['content' => '00ZH'])
        ->assertUnprocessable()
        ->assertJsonPath('detail', 'Il file non contiene record di tariffa ESTV (record 06).');
});

test('the console imports a tariff file as generic data for every company', function (): void {
    auth()->forgetGuards();
    $path = tempnam(sys_get_temp_dir(), 'tariff');
    file_put_contents($path, payrollTariffFile('00412'));

    $this->artisan('payroll:import-tariffs', ['file' => $path])->assertSuccessful();
    $this->artisan('payroll:import-tariffs', ['file' => $path])->assertSuccessful();
    unlink($path);

    expect(PayrollWithholdingTaxTariff::query()->withoutGlobalScopes()->whereNull('tenant_id')->count())->toBe(1);

    payrollApi($this->otherManager, $this->otherTenant)->getJson('/api/v1/payroll/withholding-tax-tariffs')
        ->assertOk()
        ->assertJsonPath('data.0.is_generic', true);
});

test('an identifier that is not a UUID is a 404, not a database error', function (): void {
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/runs/abc')->assertNotFound();
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/payslips?employee_id=abc')->assertUnprocessable();
    payrollApi($this->manager, $this->tenant)->getJson('/api/v1/payroll/employee-profiles?employee_id=abc')->assertUnprocessable();
});

test('sensitive fields are encrypted at rest and stay out of the audit ledger', function (): void {
    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", [
        'social_security_number' => '756.1234.5678.97',
        'iban' => 'CH9300762011623852957',
        'street' => 'Via Nassa',
    ])->assertOk()
        ->assertJsonPath('data.social_security_number', '756.1234.5678.97')
        ->assertJsonPath('data.iban', 'CH9300762011623852957');

    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['iban' => 'CH5604835012345678009'])->assertOk();

    $raw = DB::table('payroll_employee_profiles')->where('employee_id', $this->employee->id)->first();

    expect($raw->social_security_number)->not->toContain('756.1234')
        ->and($raw->iban)->not->toContain('CH56')
        ->and($raw->street)->not->toContain('Nassa')
        ->and(PayrollEmployeeProfile::query()->withoutGlobalScopes()->where('employee_id', $this->employee->id)->sole()->iban)->toBe('CH5604835012345678009')
        ->and(AuditLedgerEntry::query()->get()->filter(fn ($entry) => str_contains((string) json_encode($entry->getAttributes()), 'CH9300762011623852957')))->toHaveCount(0);
});

test('the AHV number format is checked for Swiss companies', function (): void {
    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['social_security_number' => '123'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('social_security_number');

    payrollApi($this->manager, $this->tenant)->putJson("/api/v1/payroll/employee-profiles/{$this->employee->id}", ['is_withholding_tax_subject' => true, 'withholding_tax_code' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('withholding_tax_code');
});
