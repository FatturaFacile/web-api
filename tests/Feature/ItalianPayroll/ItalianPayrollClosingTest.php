<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\HumanResources\Models\Employee;
use Modules\ItalianPayroll\Domain\Enums\GlRole;
use Modules\ItalianPayroll\Domain\Exceptions\PayrollPeriodLockedException;
use Modules\ItalianPayroll\Infrastructure\Models\ItalianPayrollPeriod;
use Modules\ItalianPayroll\Infrastructure\Models\LulAttendanceExportRecord;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use setasign\Fpdi\Fpdi;

uses(RefreshDatabase::class);

const IPP_CF_ROSSI = 'RSSMRA85T10A562S';
const IPP_CF_BIANCHI = 'BNCLRA90A41H501F';

/**
 * @return array{tenant: Tenant, user: User, rossi: Employee, bianchi: Employee}
 */
function ippWorld(string $role = 'owner'): array
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $tenant = Tenant::create([
        'name' => 'Paghe '.Str::random(5), 'legal_name' => 'Paghe Srl', 'country' => 'IT', 'default_currency' => 'EUR',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT), 'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
    ]);
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
    fiscalCalendar($tenant, 2027);

    $rossi = Employee::factory()->create(['tenant_id' => $tenant->id, 'tax_code' => IPP_CF_ROSSI, 'user_id' => $user->id, 'full_name' => 'Mario Rossi']);
    $bianchi = Employee::factory()->create(['tenant_id' => $tenant->id, 'tax_code' => IPP_CF_BIANCHI, 'full_name' => 'Lara Bianchi']);

    return compact('tenant', 'user', 'rossi', 'bianchi');
}

function ippSeedAttendance(array $world, ?string $sickProtocol): void
{
    $tenantId = (string) $world['tenant']->id;
    $now = now();

    // Rossi works a 10 hour and a 12 hour day: 16 ordinary, 4 STR25 and 2 STR50 hours, 2 meals.
    foreach ([['2027-03-02', 600], ['2027-03-03', 720]] as [$date, $minutes]) {
        DB::table('time_daily_records')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $world['user']->id, 'work_date' => $date, 'worked_minutes' => $minutes, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('time_meal_bookings')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $world['user']->id, 'meal_date' => $date, 'meal_type' => 'lunch', 'occurred_at' => $now, 'source' => 'manual', 'created_at' => $now, 'updated_at' => $now]);
    }

    DB::table('expenses')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'expense_number' => 'EXP-1', 'category_id' => (string) Str::uuid(), 'date' => '2027-03-04', 'amount_net' => 40, 'tax_amount' => 5.30,
        'amount_gross' => 45.30, 'status' => 'approved', 'is_reimbursable' => true, 'user_id' => $world['user']->id, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $typeId = (string) Str::uuid();
    DB::table('hr_leave_types')->insert(['id' => $typeId, 'tenant_id' => $tenantId, 'code' => 'MAL', 'name' => 'Malattia', 'category' => 'sick', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('hr_leave_requests')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'employee_id' => $world['bianchi']->id, 'leave_type_id' => $typeId, 'request_number' => 'LR-1', 'start_date' => '2027-03-08',
        'end_date' => '2027-03-10', 'total_hours' => 24, 'total_days' => 3, 'status' => 'approved', 'medical_certificate_ref' => $sickProtocol, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

function ippBundle(): string
{
    $pdf = new Fpdi;

    foreach (['Rossi '.IPP_CF_ROSSI, 'Rossi pagina 2', 'Bianchi '.IPP_CF_BIANCHI, 'Sconosciuto RSSMRA80A01H501U'] as $text) {
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(0, 10, $text);
    }

    return $pdf->Output('S');
}

function ippAccounts(Tenant $tenant): array
{
    $accounts = [];

    foreach (GlRole::cases() as $index => $role) {
        $id = (string) Str::uuid();
        $type = $role->isDebit() ? 'expense' : 'liability';
        DB::table('chart_of_accounts')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'code' => (string) (6000 + $index), 'account_code' => (string) (6000 + $index), 'name' => $role->value, 'type' => $type,
            'account_type' => $type, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $accounts[] = ['role' => $role->value, 'account_id' => $id];
    }

    return $accounts;
}

it('walks a month through validation, export, payslips, accounting, SEPA and payment', function (): void {
    Storage::fake('local');
    $world = ippWorld();
    ippSeedAttendance($world, null);
    $base = '/api/v1/italian-payroll';

    $periodId = $this->postJson("{$base}/periods", ['year' => 2027, 'month' => 3])->assertCreated()->assertJsonPath('data.status', 'attendance_open')->json('data.id');
    $this->postJson("{$base}/periods", ['year' => 2027, 'month' => 3])->assertStatus(409);

    // Sickness without an INPS protocol keeps the month open and says what to fix.
    $this->postJson("{$base}/periods/{$periodId}/validate")->assertOk()
        ->assertJsonPath('data.validated', false)->assertJsonPath('data.status', 'attendance_open')->assertJsonPath('data.errors.0.code', 'missing_inps_protocol');
    $this->postJson("{$base}/periods/{$periodId}/export", ['consultant_format' => 'generic_csv', 'company_code' => 'ACME'])->assertStatus(422);

    DB::table('hr_leave_requests')->update(['medical_certificate_ref' => '0123456789']);
    $this->postJson("{$base}/periods/{$periodId}/validate", ['fringe_benefits' => [['employee_id' => $world['rossi']->id, 'amount' => 120]]])->assertOk()
        ->assertJsonPath('data.validated', true)->assertJsonPath('data.status', 'validated')->assertJsonPath('data.line_count', 4);

    $lines = LulAttendanceExportRecord::query()->where('payroll_period_id', $periodId)->get();
    $rossi = $lines->where('employee_id', $world['rossi']->id);
    expect($rossi->firstWhere('causal_code.value', 'ORD')->hours_or_days)->toEqual('16.00')
        ->and($rossi->firstWhere('causal_code.value', 'ORD')->meal_vouchers_count)->toBe(2)
        ->and($rossi->firstWhere('causal_code.value', 'ORD')->reimbursement_amount)->toEqual('45.30')
        ->and($rossi->firstWhere('causal_code.value', 'ORD')->fringe_benefit_amount)->toEqual('120.00')
        ->and($rossi->firstWhere('causal_code.value', 'STR25')->hours_or_days)->toEqual('4.00')
        ->and($rossi->firstWhere('causal_code.value', 'STR50')->hours_or_days)->toEqual('2.00')
        ->and($lines->firstWhere('causal_code.value', 'MAL')->inps_protocol_number)->toBe('0123456789')
        ->and($lines->firstWhere('causal_code.value', 'MAL')->hours_or_days)->toEqual('3.00');

    // Once validated the attendance is frozen.
    expect(fn () => $lines->first()->update(['hours_or_days' => 1]))->toThrow(PayrollPeriodLockedException::class);

    $this->postJson("{$base}/periods/{$periodId}/export", ['consultant_format' => 'zucchetti', 'company_code' => 'ACME'])->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="ZUCCHETTI_ACME_202703.txt"');
    expect(ItalianPayrollPeriod::query()->find($periodId)->status->value)->toBe('exported_to_cdl');

    $bundle = UploadedFile::fake()->createWithContent('cedolini.pdf', ippBundle());
    $this->post("{$base}/periods/{$periodId}/cedolini", ['bundle_pdf' => $bundle], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonCount(2, 'data.matched')->assertJsonCount(1, 'data.unmatched')->assertJsonPath('data.matched.0.pages', '1-2')->assertJsonPath('data.status', 'cedolini_imported');
    expect(Storage::disk('local')->allFiles())->toHaveCount(2);

    $accounts = ippAccounts($world['tenant']);
    $this->putJson("{$base}/gl-accounts", ['accounts' => $accounts])->assertOk();

    $summary = [
        'total_gross_wages' => 5000, 'inps_employee_withholding' => 459.50, 'inps_employer_contribution' => 1500, 'irpef_withholding' => 700, 'regional_municipal_addizionali' => 60,
        'tfr_monthly_accrual' => 370.37, 'tfr_fondo_tesoreria_or_previdenza' => 370.37, 'net_payable' => 3780.50,
    ];
    $nets = [['employee_id' => $world['rossi']->id, 'net_amount' => 2000], ['employee_id' => $world['bianchi']->id, 'net_amount' => 1780.50]];

    $this->postJson("{$base}/periods/{$periodId}/accounting-import", $summary + ['employee_nets' => [['employee_id' => $world['rossi']->id, 'net_amount' => 1]]])->assertStatus(422);
    $this->postJson("{$base}/periods/{$periodId}/accounting-import", array_merge($summary, ['net_payable' => 3000]))->assertStatus(422);
    $entryId = $this->postJson("{$base}/periods/{$periodId}/accounting-import", $summary + ['employee_nets' => $nets])->assertCreated()->json('data.gl_journal_entry_id');
    $this->postJson("{$base}/periods/{$periodId}/accounting-import", $summary + ['employee_nets' => $nets])->assertStatus(422);

    $entry = JournalEntry::query()->with('lines')->findOrFail($entryId);
    expect($entry->lines)->toHaveCount(8)
        ->and((float) $entry->lines->sum('debit_amount'))->toBe((float) $entry->lines->sum('credit_amount'))
        ->and((float) $entry->lines->sum('debit_amount'))->toBe(6870.37);

    $f24 = $this->getJson("{$base}/periods/{$periodId}/f24")->assertOk()->json('data');
    expect($f24['due_date'])->toBe('2027-04-16')->and($f24['total'])->toBe('760.00');

    // The SEPA file needs the IBANs; then it carries the exact nets.
    $sepa = ['debtor_name' => 'Paghe Srl', 'debtor_iban' => 'IT60X0542811101000000123456', 'execution_date' => now()->addDay()->toDateString()];
    $this->postJson("{$base}/periods/{$periodId}/sepa", $sepa)->assertStatus(422);
    PayrollEmployeeProfile::factory()->create(['tenant_id' => $world['tenant']->id, 'employee_id' => $world['rossi']->id, 'iban' => 'IT60X0542811101000000123456']);
    PayrollEmployeeProfile::factory()->create(['tenant_id' => $world['tenant']->id, 'employee_id' => $world['bianchi']->id, 'iban' => 'DE89370400440532013000']);
    $xml = $this->postJson("{$base}/periods/{$periodId}/sepa", $sepa)->assertOk()->getContent();
    expect($xml)->toContain('pain.001.001.03')->toContain('<Cd>SALA</Cd>')->toContain('<CtrlSum>3780.50</CtrlSum>');

    // The employee opens their own payslip: the first opening is the receipt, every opening is audited.
    $vault = $this->getJson("{$base}/my-payslips")->assertOk()->assertJsonPath('data.0.month', 3)->assertJsonPath('data.0.has_cedolino', true)->json('data');
    $this->postJson("{$base}/my-payslips/{$vault[0]['distribution_id']}/open", ['document_kind' => 'cedolino', 'biometric_confirmed' => true])->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    $this->postJson("{$base}/my-payslips/{$vault[0]['distribution_id']}/open", ['document_kind' => 'cu'])->assertStatus(422);
    $audit = $this->getJson("{$base}/periods/{$periodId}/download-audit?employee_id={$world['rossi']->id}")->assertOk()->json('data.0');
    expect($audit['first_viewed_at'])->not->toBeNull()->and($audit['events'])->toHaveCount(1)->and($audit['events'][0]['biometric'])->toBeTrue();

    $this->postJson("{$base}/periods/{$periodId}/mark-paid")->assertOk()->assertJsonPath('data.status', 'paid');
    $this->postJson("{$base}/periods/{$periodId}/validate")->assertStatus(422);

    $ledger = $this->getJson("{$base}/tfr-ledger?year=2027&foi_percent=2")->assertOk()->json('data');
    expect($ledger['total'])->toBe('370.37')->and($ledger['revaluation']['gross'])->toBe('11.11');
});

it('keeps the pay data from members without payroll access', function (): void {
    ippWorld('member');

    $this->getJson('/api/v1/italian-payroll/periods')->assertForbidden();
    $this->getJson('/api/v1/italian-payroll/my-payslips')->assertOk();
});

it('does not show one tenant the periods of another', function (): void {
    $world = ippWorld();
    $this->postJson('/api/v1/italian-payroll/periods', ['year' => 2027, 'month' => 4])->assertCreated();

    $other = ippWorld();
    $this->getJson('/api/v1/italian-payroll/periods')->assertOk()->assertJsonCount(0, 'data');
    expect($world['tenant']->id)->not->toBe($other['tenant']->id);
});
