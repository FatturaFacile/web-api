<?php

declare(strict_types=1);

use App\Actions\Sales\SendPaymentReminder;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\Security\KeyDerivation;
use App\Support\Security\SafeXmlLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimeRecordingProfile;
use Modules\Payroll\Models\PayrollEmployeeProfile;
use Modules\Payroll\Models\PayrollInput;

uses(RefreshDatabase::class);

test('Phase F: ArG Art. 17b Night Work Compensation Bank (<25 shifts 25% cash vs >=25 shifts 10% mandatory rest time) and automatic Time-to-Payroll transfer', function (): void {
    $tenant = Tenant::create([
        'name' => 'Swiss Time Tenant '.Str::random(4),
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $manager = User::create([
        'name' => 'HR Manager',
        'email' => Str::random(8).'@time.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $manager->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    $workerUser = User::create([
        'name' => 'Luca Notturno',
        'email' => Str::random(8).'@worker.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $workerUser->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'employee']);

    app(TenantContext::class)->setTenant($tenant);

    $employee = Employee::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'user_id' => $workerUser->id,
        'employee_code' => 'EMP-NIGHT-1',
        'first_name' => 'Luca',
        'last_name' => 'Notturno',
        'full_name' => 'Luca Notturno',
        'hire_date' => '2025-01-01',
        'employment_type' => 'hourly',
        'status' => 'active',
    ]);

    PayrollEmployeeProfile::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'employee_id' => $employee->id,
        'pay_type' => 'hourly',
        'hourly_wage' => '40.00',
        'weekly_hours' => '42.0',
        'work_canton' => 'TI',
    ]);

    TimeRecordingProfile::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'user_id' => $workerUser->id,
        'recording_mode' => 'full',
        'canton' => 'TI',
        'weekly_max_hours' => 45,
        'daily_target_minutes' => 504,
        'is_regular_night_worker' => false,
    ]);

    // Case 1: Occasional night work in January 2026 (2 night shifts < 25 threshold) -> generates NIGHT_125 (1020) 25% cash supplement
    foreach (['2026-01-12', '2026-01-13'] as $day) {
        TimeDailyRecord::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $tenant->id,
            'user_id' => $workerUser->id,
            'work_date' => $day,
            'worked_minutes' => 480,
            'break_minutes' => 30,
            'night_minutes' => 180, // 3h night work per shift
        ]);
    }

    Sanctum::actingAs($manager);
    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    $janTransfer = $this->withHeaders($headers)
        ->postJson('/api/v1/hr/time-closings/2026/1/transfer-to-payroll')
        ->assertOk()
        ->json('data');

    expect($janTransfer['employees_processed'])->toBe(1)
        ->and(PayrollInput::where('employee_id', $employee->id)->where('year', 2026)->where('month', 1)->where('wage_type_code', '1001')->exists())->toBeTrue()
        ->and(PayrollInput::where('employee_id', $employee->id)->where('year', 2026)->where('month', 1)->where('wage_type_code', '1020')->exists())->toBeTrue();

    // Case 2: Regular night worker (>= 25 shifts or profile flag) -> ArG Art. 17b al. 2 mandatory 10% rest time bank, NO cash supplement 1020
    TimeRecordingProfile::where('user_id', $workerUser->id)->update(['is_regular_night_worker' => true]);

    $janTransferRegular = $this->withHeaders($headers)
        ->postJson('/api/v1/hr/time-closings/2026/1/transfer-to-payroll')
        ->assertOk()
        ->json('data');

    expect($janTransferRegular['night_time_bank_credits'])->toBe(1)
        ->and(PayrollInput::where('employee_id', $employee->id)->where('year', 2026)->where('month', 1)->where('wage_type_code', '1020')->exists())->toBeFalse();

    $balances = $this->withHeaders($headers)
        ->getJson("/api/v1/hr/time/night-work-balances?year=2026&user_id={$workerUser->id}")
        ->assertOk()
        ->json('data');

    // 360 night minutes * 10% = 36 minutes accrued rest time in the ArG Art. 17b bank
    expect($balances[0]['is_regular_night_worker'])->toBeTrue()
        ->and($balances[0]['total_night_minutes'])->toBe(360)
        ->and($balances[0]['accrued_rest_minutes'])->toBe(36)
        ->and($balances[0]['compensation_mode'])->toBe('time_10_percent');
});

test('Phase G: Swiss Dunning Levels 1, 2, 3 with configurable fees, CO Art. 104 5% p.a. statutory default interest, and QR-Bill attachment', function (): void {
    Mail::fake();
    $this->travelTo('2026-11-10 10:00:00');

    $tenant = Tenant::create([
        'name' => 'Swiss Dunning AG',
        'legal_name' => 'Swiss Dunning AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'iban' => 'CH9300762011623852957',
    ]);

    app(TenantContext::class)->setTenant($tenant);

    $contact = Contact::create([
        'tenant_id' => (string) $tenant->id,
        'company_name' => 'Debitore Ticino SA',
        'email' => 'debitore@ticino.ch',
        'type' => 'customer',
        'country' => 'CH',
    ]);
    $contact->addresses()->create([
        'type' => 'billing',
        'street' => 'Via Nassa 10',
        'city' => 'Lugano',
        'postal_code' => '6900',
        'country' => 'CH',
        'is_primary' => true,
    ]);

    // Invoice of 1000.00 CHF overdue by 73 days (73 / 365 = 0.2 years -> 5% p.a. interest = 10.00 CHF)
    $invoice = Invoice::create([
        'tenant_id' => (string) $tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-CH-2026-001',
        'invoice_type' => 'invoice',
        'jurisdiction' => 'CH',
        'issue_date' => '2026-07-30',
        'due_date' => '2026-08-29',
        'currency' => 'CHF',
        'subtotal' => '1000.00',
        'tax' => '0.00',
        'total' => '1000.00',
        'status' => 'issued',
        'payment_status' => 'unpaid',
    ]);

    $action = app(SendPaymentReminder::class);

    // Level 1 reminder: fee = 0.00 CHF, CO Art. 104 5% interest = 10.00 CHF, QR-Bill attached
    $r1 = $action->execute($invoice, true);
    expect($r1)->not->toBeNull()
        ->and($r1->level)->toBe(1)
        ->and((float) $r1->dunning_fee)->toBe(0.0)
        ->and((float) $r1->interest_rate_percent)->toBe(5.0)
        ->and((float) $r1->interest_amount)->toBe(10.0)
        ->and((float) $r1->total_claimed_amount)->toBe(1010.0)
        ->and($r1->qr_bill_attached)->toBeTrue();

    // Level 2 reminder: fee = 20.00 CHF
    $r2 = $action->execute($invoice->fresh(), true);
    expect($r2->level)->toBe(2)
        ->and((float) $r2->dunning_fee)->toBe(20.0)
        ->and((float) $r2->total_claimed_amount)->toBe(1030.0);

    // Level 3 reminder: fee = 50.00 CHF
    $r3 = $action->execute($invoice->fresh(), true);
    expect($r3->level)->toBe(3)
        ->and((float) $r3->dunning_fee)->toBe(50.0)
        ->and((float) $r3->total_claimed_amount)->toBe(1060.0);
});

test('Phase H: Security Hardening — SafeXmlLoader blocks XXE DOCTYPE/ENTITY payloads and KeyDerivation derives isolated HKDF subkeys', function (): void {
    $xxeXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE foo [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
<Document>&xxe;</Document>
XML;

    expect(fn () => SafeXmlLoader::loadSimpleXml($xxeXml))
        ->toThrow(InvalidArgumentException::class, 'DOCTYPE and ENTITY declarations are prohibited');

    expect(fn () => SafeXmlLoader::loadDom($xxeXml))
        ->toThrow(InvalidArgumentException::class, 'DOCTYPE and ENTITY declarations are prohibited');

    $subkeyBlindIndex = KeyDerivation::derive('associations.blind_index.v1');
    $subkeyBackupHmac = KeyDerivation::derive('system.tenant_backup.hmac.v1');

    expect(strlen($subkeyBlindIndex))->toBe(32)
        ->and(strlen($subkeyBackupHmac))->toBe(32)
        ->and($subkeyBlindIndex)->not->toBe($subkeyBackupHmac);
});

test('Phase I: Global GDPR & Swiss nLPD Compliance Module (Art. 30/12 RoPA, Art. 16 Cross-Border Subprocessors, Portability Export, Erasure with CO Art. 958f 10-year Legal Hold, Breach 72h Deadline)', function (): void {
    $tenant = Tenant::create([
        'name' => 'Swiss Privacy AG',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $admin = User::create([
        'name' => 'DPO Officer',
        'email' => Str::random(8).'@privacy.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $admin->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($admin);
    $headers = ['X-Tenant-Id' => (string) $tenant->id];

    // 1. Register Processing Activity (GDPR Art. 30 / Swiss nLPD Art. 12)
    $this->withHeaders($headers)
        ->postJson('/api/v1/settings/privacy/processing-activities', [
            'title' => 'Swiss Payroll & ELM Transmission',
            'purpose' => 'Statutory salary calculation, AHV/AVS and Quellensteuer declaration',
            'legal_basis' => 'legal_obligation',
            'data_categories' => ['identity', 'salary', 'ahv_number', 'tax_at_source'],
            'subject_categories' => ['employees'],
            'retention_period' => '10 years (Swiss CO Art. 958f)',
        ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Swiss Payroll & ELM Transmission');

    // 2. Register Subprocessors (Swiss nLPD Art. 16 adequacy check: CH = adequate, US = requires SCC)
    $subUs = $this->withHeaders($headers)
        ->postJson('/api/v1/settings/privacy/subprocessors', [
            'name' => 'Cloud Analytics Inc.',
            'service_type' => 'Infrastructure',
            'country_code' => 'US',
        ])
        ->assertCreated()
        ->json('data');

    expect($subUs['is_adequate_country_nlpd'])->toBeFalse()
        ->and($subUs['safeguard_mechanism'])->toBe('scc_fdpic');

    // 3. Create Contact with recent Invoice (< 10 years) -> test Portability & Erasure with CO Art. 958f Legal Hold
    $contact = Contact::create([
        'tenant_id' => (string) $tenant->id,
        'company_name' => 'Hans Meier Consulting',
        'first_name' => 'Hans',
        'last_name' => 'Meier',
        'email' => 'hans.meier@example.ch',
        'phone' => '+41791234567',
        'iban' => 'CH9300762011623852957',
        'type' => 'customer',
        'country' => 'CH',
    ]);

    Invoice::create([
        'tenant_id' => (string) $tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-2026-999',
        'invoice_type' => 'invoice',
        'issue_date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'currency' => 'CHF',
        'subtotal' => '500.00',
        'tax' => '0.00',
        'total' => '500.00',
        'status' => 'issued',
        'payment_status' => 'paid',
    ]);

    $dossier = $this->withHeaders($headers)
        ->getJson("/api/v1/settings/privacy/portability/contact/{$contact->id}")
        ->assertOk()
        ->json('data');

    expect($dossier['schema'])->toBe('urn:fatturafacile:privacy:portability:1.0')
        ->and($dossier['personal_data']['email'])->toBe('hans.meier@example.ch')
        ->and($dossier['commercial_records']['invoices'])->toHaveCount(1);

    $dsr = $this->withHeaders($headers)
        ->postJson('/api/v1/settings/privacy/data-subject-requests', [
            'request_type' => 'erasure',
            'regulation' => 'nLPD',
            'subject_type' => 'contact',
            'subject_id' => $contact->id,
        ])
        ->assertCreated()
        ->json('data');

    $erasedDsr = $this->withHeaders($headers)
        ->postJson("/api/v1/settings/privacy/data-subject-requests/{$dsr['id']}/execute-erasure")
        ->assertOk()
        ->json('data');

    expect($erasedDsr['status'])->toBe('partially_completed_legal_hold')
        ->and($erasedDsr['legal_holds'][0]['statute'])->toBe('Swiss CO Art. 958f')
        ->and($contact->fresh()->phone)->toBeNull()
        ->and($contact->fresh()->processing_restricted_at)->not->toBeNull()
        ->and($contact->fresh()->company_name)->toBe('Hans Meier Consulting'); // Preserved for 10-year CO Art. 958f invoice hold!

    // 4. Report Data Breach (72h deadline + FDPIC/GDPR notification flags)
    $breach = $this->withHeaders($headers)
        ->postJson('/api/v1/settings/privacy/breaches', [
            'title' => 'Unauthorized export attempt',
            'severity' => 'high',
            'discovered_at' => '2026-11-10T08:00:00Z',
            'affected_records_count' => 42,
            'data_categories' => ['email', 'iban'],
        ])
        ->assertCreated()
        ->json('data');

    expect($breach['requires_authority_notification'])->toBeTrue()
        ->and($breach['requires_subject_notification'])->toBeTrue();

    // 5. Verify DPOOP Art. 4 sensitive access log was recorded for the portability export
    $logs = $this->withHeaders($headers)
        ->getJson('/api/v1/settings/privacy/sensitive-access-logs')
        ->assertOk()
        ->json('data');

    expect($logs)->not->toBeEmpty()
        ->and($logs[0]['action'])->toBe('portability_export');
});
