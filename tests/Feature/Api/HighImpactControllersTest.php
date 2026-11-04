<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\AttendanceRecord;
use Modules\HumanResources\Models\Employee;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Enterprise HQ',
        'legal_name' => 'Enterprise HQ S.p.A.',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::create([
        'name' => 'Enterprise Admin',
        'email' => 'admin@enterprise.eu',
        'password' => bcrypt('password123'),
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    Sanctum::actingAs($this->user);
});

function apiRequest()
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

function attendanceCorrectionShift(): TimeEntry
{
    return TimeEntry::create([
        'tenant_id' => test()->tenant->id,
        'user_id' => test()->user->id,
        'clock_in' => '2026-10-15 08:00:00',
        'clock_out' => '2026-10-15 16:00:00',
        'break_minutes' => 30,
    ]);
}

/** @return array{clock_in: string, clock_out: string, break_minutes: int, reason: string} */
function attendanceCorrectionPayload(): array
{
    return [
        'clock_in' => '2026-10-15T08:00:00Z',
        'clock_out' => '2026-10-15T17:00:00Z',
        'break_minutes' => 60,
        'reason' => 'The recorded exit and break duration were incorrect.',
    ];
}

function attendanceCorrectionReviewer(): User
{
    $reviewer = User::factory()->create();
    $reviewer->tenants()->attach(test()->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'manager']);
    Gate::define('approve-attendance-corrections', fn (User $actor): bool => $actor->id === $reviewer->id);

    return $reviewer;
}

// ------------------------------------------------------------------------------------------------
// PurchasesController Endpoints
// ------------------------------------------------------------------------------------------------

test('purchases controller retrieves paginated lists of requests, orders, and passive invoices', function (): void {
    PurchaseRequest::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'request_number' => 'REQ-001',
        'requested_date' => now()->toDateString(),
        'status' => 'pending',
    ]);

    $supplier = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Supplier Co',
        'type' => 'supplier',
        'country' => 'IT',
    ]);

    PurchaseOrder::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'order_number' => 'PO-001',
        'date' => now()->toDateString(),
        'status' => 'draft',
        'total_net' => 500,
        'total_gross' => 610,
    ]);

    PassiveInvoice::create([
        'tenant_id' => $this->tenant->id,
        'supplier_id' => $supplier->id,
        'invoice_number' => 'PI-001',
        'date' => now()->toDateString(),
        'total_amount' => 610,
        'currency' => 'EUR',
    ]);

    apiRequest()->getJson('/api/v1/purchases/requests')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    apiRequest()->getJson('/api/v1/purchases/orders')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    apiRequest()->getJson('/api/v1/purchases/passive-invoices')
        ->assertOk()
        ->assertJsonPath('data.total', 1);
});

test('purchases controller imports fatturapa xml with validation and edge cases', function (): void {
    // 1. Invalid XML
    $invalidFile = UploadedFile::fake()->createWithContent('invalid.xml', 'Not an XML payload');
    apiRequest()->postJson('/api/v1/purchases/passive-invoices/import-sdi', [
        'xml_file' => $invalidFile,
    ])->assertStatus(422);

    // 2. Valid FatturaPA XML
    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA>
          <IdPaese>IT</IdPaese>
          <IdCodice>09876543210</IdCodice>
        </IdFiscaleIVA>
        <Anagrafica>
          <Denominazione>Acme Tech SRL</Denominazione>
        </Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <Numero>FT-999</Numero>
        <Data>2026-10-31</Data>
        <Divisa>EUR</Divisa>
        <ImportoTotaleDocumento>305.00</ImportoTotaleDocumento>
      </DatiGeneraliDocumento>
    </DatiGenerali>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;

    $validFile = UploadedFile::fake()->createWithContent('fattura.xml', $xmlContent);
    $response = apiRequest()->postJson('/api/v1/purchases/passive-invoices/import-sdi', [
        'xml_file' => $validFile,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.invoice_number', 'FT-999')
        ->assertJsonPath('data.total_amount', fn ($val) => (float) $val === 305.0)
        ->assertJsonPath('data.supplier', 'Acme Tech SRL');
});

test('purchases controller scans swiss qr bill payload and exports pain001', function (): void {
    // 1. Invalid QR payload
    apiRequest()->postJson('/api/v1/purchases/passive-invoices/scan-qr-bill', [
        'qr_payload' => 'INVALID-HEADER',
    ])->assertStatus(422);

    // 2. Valid Swiss SPC QR payload using \n separation
    $qrLines = array_fill(0, 32, '');
    $qrLines[0] = 'SPC';
    $qrLines[1] = '0200';
    $qrLines[2] = '1';
    $qrLines[3] = 'CH4431999123000889012';
    $qrLines[5] = 'Swiss Telecom AG';
    $qrLines[18] = '149.90';
    $qrLines[19] = 'CHF';
    $qrLines[27] = '210000000003139471430009017';

    $payload = implode("\n", $qrLines);

    $qrResponse = apiRequest()->postJson('/api/v1/purchases/passive-invoices/scan-qr-bill', [
        'qr_payload' => $payload,
    ]);

    $qrResponse->assertCreated()
        ->assertJsonPath('data.creditor', 'Swiss Telecom AG')
        ->assertJsonPath('data.amount', fn ($val) => (float) $val === 149.90)
        ->assertJsonPath('data.currency', 'CHF');

    // 3. Export pain.001 XML
    $painResponse = apiRequest()->postJson('/api/v1/purchases/export-pain001', [
        'invoice_ids' => [$qrResponse->json('data.id')],
        'format' => 'sepa_pain_001_it',
    ]);

    $painResponse->assertOk()
        ->assertJsonPath('data.format', 'sepa_pain_001_it')
        ->assertJsonStructure(['data' => ['batch_id', 'message_id', 'xml_preview']]);
});

// ------------------------------------------------------------------------------------------------
// TimeAttendanceController Endpoints
// ------------------------------------------------------------------------------------------------

test('time attendance controller manages clock-in, clock-out lifecycle and reports', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    // 1. Clock in
    $clockInResponse = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'latitude' => 45.4642,
        'longitude' => 9.1900,
        'notes' => 'Morning shift',
    ]);
    $clockInResponse->assertCreated();

    // 2. Clock in again while active shift exists -> 409 Conflict
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertStatus(409);

    // 3. Clock out with invalid timestamp before clock in
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->subHours(2)->format('Y-m-d H:i:s'),
    ])->assertStatus(422);

    // 4. Clock out successfully
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated();
    $this->travel(45)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertCreated();
    $this->travelTo(now()->setTime(16, 0));
    $clockOutResponse = apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'notes' => 'Completed shift',
    ]);
    $clockOutResponse->assertOk();

    // 5. Clock out when no active shift exists -> 422
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertStatus(422);

    // 6. List entries
    apiRequest()->getJson('/api/v1/time-attendance/entries')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);

    // 7. Compliance check
    apiRequest()->getJson('/api/v1/time-attendance/compliance-check')
        ->assertOk()
        ->assertJsonPath('data.jurisdiction', 'IT');

    // 8. Monthly report
    apiRequest()->getJson('/api/v1/time-attendance/monthly-report?month='.now()->month.'&year='.now()->year)
        ->assertOk()
        ->assertJsonStructure(['data' => ['summary', 'compliance', 'entries']]);
});

test('closed time-entry shifts synchronize daily totals to the linked HR employee', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'company_id' => $this->tenant->id,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));

    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated();
    $this->travel(30)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertCreated();
    $this->travel(30)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $attendance = AttendanceRecord::where('tenant_id', $this->tenant->id)
        ->where('employee_id', $employee->id)
        ->where('work_date', '2026-10-15')
        ->sole();
    expect($attendance->source)->toBe('time_attendance_api')
        ->and($attendance->clock_in_at->toDateTimeString())->toBe('2026-10-15 08:00:00')
        ->and($attendance->clock_out_at->toDateTimeString())->toBe('2026-10-15 13:00:00')
        ->and($attendance->break_minutes)->toBe(30)
        ->and((float) $attendance->recorded_hours)->toBe(4.5)
        ->and((float) $attendance->regular_hours)->toBe(0.0)
        ->and((float) $attendance->overtime_hours)->toBe(0.0);
});

test('closed shifts recalculate an HR daily record and preserve contract hours classification', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'company_id' => $this->tenant->id,
    ]);

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $this->travelTo(now()->setTime(13, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $attendance = AttendanceRecord::where('tenant_id', $this->tenant->id)
        ->where('employee_id', $employee->id)
        ->where('work_date', '2026-10-15')
        ->sole();
    expect($attendance->clock_in_at->toDateTimeString())->toBe('2026-10-15 08:00:00')
        ->and($attendance->clock_out_at->toDateTimeString())->toBe('2026-10-15 17:00:00')
        ->and((float) $attendance->recorded_hours)->toBe(8.0)
        ->and((float) $attendance->regular_hours)->toBe(0.0)
        ->and((float) $attendance->overtime_hours)->toBe(0.0);
});

test('time attendance does not overwrite an HR daily record from another source', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'company_id' => $this->tenant->id,
    ]);
    AttendanceRecord::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-10-15',
        'clock_in_at' => '2026-10-15 09:00:00',
        'clock_out_at' => '2026-10-15 17:00:00',
        'break_minutes' => 60,
        'recorded_hours' => 7,
        'regular_hours' => 7,
        'overtime_hours' => 0,
        'status' => 'present',
        'source' => 'web_portal',
    ]);

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(8)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $attendance = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($attendance->source)->toBe('web_portal')
        ->and($attendance->clock_in_at->toDateTimeString())->toBe('2026-10-15 09:00:00')
        ->and((float) $attendance->recorded_hours)->toBe(7.0);
});

test('attendance report permits an explicitly selected personal report', function (string $period): void {
    apiRequest()->getJson('/api/v1/time-attendance/'.$period.'-report?user_id='.$this->user->id)
        ->assertOk()
        ->assertJsonPath('data.user.id', $this->user->id);
})->with(['monthly', 'annual']);

test('attendance role permissions map tenant roles to attendance gates', function (): void {
    $manager = User::factory()->create();
    $manager->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'manager',
        'permissions' => json_encode(['view-all-attendance', 'approve-attendance-corrections']),
    ]);

    $employee = User::factory()->create();
    $employee->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
        'permissions' => json_encode([]),
    ]);

    expect($manager->can('view-all-attendance'))->toBeTrue()
        ->and($manager->can('approve-attendance-corrections'))->toBeTrue()
        ->and($employee->can('view-all-attendance'))->toBeFalse()
        ->and($employee->can('approve-attendance-corrections'))->toBeFalse();
});

test('attendance report forbids another tenant member without permission', function (string $period): void {
    $colleague = User::factory()->create();
    $colleague->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
    ]);

    apiRequest()->getJson('/api/v1/time-attendance/'.$period.'-report?user_id='.$colleague->id)
        ->assertForbidden();
})->with(['monthly', 'annual']);

test('attendance report permits another tenant member with permission', function (string $period): void {
    Gate::define('view-all-attendance', fn (User $user): bool => $user->id === $this->user->id);

    $colleague = User::factory()->create();
    $colleague->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
    ]);

    apiRequest()->getJson('/api/v1/time-attendance/'.$period.'-report?user_id='.$colleague->id)
        ->assertOk()
        ->assertJsonPath('data.user.id', $colleague->id);
})->with(['monthly', 'annual']);

test('attendance report rejects a member of another tenant even with permission', function (string $period): void {
    Gate::define('view-all-attendance', fn (User $user): bool => $user->id === $this->user->id);

    $otherTenant = Tenant::factory()->create();
    $outsider = User::factory()->create();
    $outsider->tenants()->attach($otherTenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
    ]);

    apiRequest()->getJson('/api/v1/time-attendance/'.$period.'-report?user_id='.$outsider->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
})->with(['monthly', 'annual']);

test('attendance report rejects a deleted tenant member', function (string $period): void {
    Gate::define('view-all-attendance', fn (User $user): bool => $user->id === $this->user->id);

    $colleague = User::factory()->create();
    $colleague->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
    ]);
    $colleague->delete();

    apiRequest()->getJson('/api/v1/time-attendance/'.$period.'-report?user_id='.$colleague->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
})->with(['monthly', 'annual']);

test('attendance annual report aggregates twelve months and excludes other users tenants and years', function (): void {
    foreach ([
        ['2026-01-15 08:00:00', '2026-01-15 16:00:00', 60],
        ['2026-02-15 08:00:00', '2026-02-15 12:00:00', 30],
        ['2026-12-31 22:00:00', '2027-01-01 02:00:00', 0],
        ['2026-03-15 08:00:00', null, 0],
        ['2025-12-15 08:00:00', '2025-12-15 16:00:00', 0],
        ['2027-01-15 08:00:00', '2027-01-15 16:00:00', 0],
    ] as [$clockIn, $clockOut, $breakMinutes]) {
        TimeEntry::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'break_minutes' => $breakMinutes,
        ]);
    }

    $colleague = User::factory()->create();
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $colleague->id,
        'clock_in' => '2026-01-20 08:00:00',
        'clock_out' => '2026-01-20 16:00:00',
    ]);

    $otherTenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($otherTenant);
    TimeEntry::create([
        'tenant_id' => $otherTenant->id,
        'user_id' => $this->user->id,
        'clock_in' => '2026-01-21 08:00:00',
        'clock_out' => '2026-01-21 16:00:00',
    ]);
    app(TenantContext::class)->setTenant($this->tenant);

    apiRequest()->getJson('/api/v1/time-attendance/annual-report?year=2026')
        ->assertOk()
        ->assertJsonPath('data.period.year', 2026)
        ->assertJsonPath('data.period.start_date', '2026-01-01')
        ->assertJsonPath('data.period.end_date', '2026-12-31')
        ->assertJsonPath('data.summary.total_shifts', 4)
        ->assertJsonPath('data.summary.closed_shifts', 3)
        ->assertJsonPath('data.summary.open_shifts', 1)
        ->assertJsonPath('data.summary.gross_hours', fn ($hours): bool => (float) $hours === 16.0)
        ->assertJsonPath('data.summary.break_hours', fn ($hours): bool => (float) $hours === 1.5)
        ->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 14.5)
        ->assertJsonCount(12, 'data.months')
        ->assertJsonPath('data.months.0.month', 1)
        ->assertJsonPath('data.months.0.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 7.0)
        ->assertJsonPath('data.months.1.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 3.5)
        ->assertJsonPath('data.months.2.summary.open_shifts', 1)
        ->assertJsonPath('data.months.3.summary.total_shifts', 0)
        ->assertJsonPath('data.months.11.month', 12)
        ->assertJsonPath('data.months.11.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 4.0);

    apiRequest()->getJson('/api/v1/time-attendance/monthly-report?year=2026&month=1')
        ->assertOk()
        ->assertJsonPath('data.summary.gross_hours', fn ($hours): bool => (float) $hours === 8.0)
        ->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 7.0)
        ->assertJsonPath('data.entries.0.duration_hours', fn ($hours): bool => (float) $hours === 7.0);
});

test('attendance annual report defaults to the current year with twelve empty months', function (): void {
    $this->travelTo(now()->setDate(2026, 11, 2));

    apiRequest()->getJson('/api/v1/time-attendance/annual-report')
        ->assertOk()
        ->assertJsonPath('data.period.year', 2026)
        ->assertJsonPath('data.summary.total_shifts', 0)
        ->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 0.0)
        ->assertJsonCount(12, 'data.months');
});

test('attendance annual report rejects an invalid year', function (string $year): void {
    apiRequest()->getJson('/api/v1/time-attendance/annual-report?year='.$year)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('year');
})->with(['1999', '2101', 'invalid']);

test('attendance ordinary mutations reject client timestamps even when marked as corrections', function (string $action, string $field): void {
    foreach (['2020-01-01 08:00:00', '2099-01-01 08:00:00', null] as $timestamp) {
        apiRequest()->postJson('/api/v1/time-attendance/'.$action, [
            $field => $timestamp,
            'is_correction' => true,
        ], ['Idempotency-Key' => 'ordinary-'.$action])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    $this->assertDatabaseCount('time_entries', 0);
    $this->assertDatabaseCount('time_entry_events', 0);
    $this->assertDatabaseCount('attendance_requests', 0);
})->with([
    ['clock-in', 'clock_in'],
    ['clock-out', 'clock_out'],
    ['break-start', 'occurred_at'],
    ['break-end', 'occurred_at'],
]);

test('attendance records multiple break events and derives minutes for reports', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();

    $this->travelTo(now()->setTime(12, 0, 15));
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated()
        ->assertJsonPath('data.is_on_break', true)
        ->assertJsonPath('data.events.0.type', 'break_start')
        ->assertJsonPath('data.events.0.recorded_by', $this->user->id);

    $this->travelTo(now()->setTime(12, 30, 45));
    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertCreated()
        ->assertJsonPath('data.is_on_break', false)
        ->assertJsonPath('data.break_minutes', 30);

    $this->travelTo(now()->setTime(15, 0));
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated();

    $this->travelTo(now()->setTime(15, 15, 30));
    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertCreated()
        ->assertJsonPath('data.break_minutes', 46)
        ->assertJsonCount(4, 'data.events')
        ->assertJsonPath('data.events.3.sequence', 4);

    $this->travelTo(now()->setTime(16, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk()
        ->assertJsonPath('data.break_minutes', 46)
        ->assertJsonPath('data.duration_hours', fn ($hours): bool => (float) $hours === 7.23);

    apiRequest()->getJson('/api/v1/time-attendance/entries')
        ->assertOk()
        ->assertJsonCount(4, 'data.0.events');

    apiRequest()->getJson('/api/v1/time-attendance/monthly-report?year=2026&month=10')
        ->assertOk()
        ->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 7.23)
        ->assertJsonCount(4, 'data.entries.0.events');

    apiRequest()->getJson('/api/v1/time-attendance/annual-report?year=2026')
        ->assertOk()
        ->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 7.23);

    $this->assertDatabaseCount('time_entry_events', 4);
});

test('attendance break events enforce shift state and chronological order', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertConflict();

    $this->travelTo(now()->setTime(7, 0));
    apiRequest()->postJson('/api/v1/time-attendance/break-start')
        ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => 'invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    $this->travelTo(now()->setTime(12, 0));
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated();

    $this->travelTo(now()->setTime(12, 5));
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertConflict();

    $this->travelTo(now()->setTime(11, 59));
    apiRequest()->postJson('/api/v1/time-attendance/break-end')
        ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    $this->travelTo(now()->setTime(12, 30));
    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertConflict();

    $this->travelTo(now()->setTime(12, 15));
    apiRequest()->postJson('/api/v1/time-attendance/break-start')
        ->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    apiRequest()->postJson('/api/v1/time-attendance/clock-out')
        ->assertUnprocessable()->assertJsonValidationErrors('clock_out');

    $this->travelTo(now()->setTime(16, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'break_minutes' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('break_minutes');

    apiRequest()->postJson('/api/v1/time-attendance/clock-out')
        ->assertOk()->assertJsonPath('data.break_minutes', 30);

    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertUnprocessable();
    $this->assertDatabaseCount('time_entry_events', 2);
});

test('attendance break events require an active shift', function (string $action): void {
    apiRequest()->postJson('/api/v1/time-attendance/'.$action)->assertUnprocessable();
})->with(['break-start', 'break-end']);

test('attendance break events default to server time', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')
        ->assertCreated()->assertJsonPath('data.clock_in', now()->toIso8601String());

    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/break-start')
        ->assertCreated()->assertJsonPath('data.events.0.occurred_at', now()->toIso8601String());

    $this->travel(30)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/break-end')
        ->assertCreated()->assertJsonPath('data.break_minutes', 30)
        ->assertJsonPath('data.events.1.occurred_at', now()->toIso8601String());

    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')
        ->assertOk()->assertJsonPath('data.clock_out', now()->toIso8601String());
});

test('attendance break events cannot act on another user or tenant shift', function (): void {
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();

    $colleague = User::factory()->create();
    $colleague->tenants()->attach($this->tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
    ]);
    Sanctum::actingAs($colleague);
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertUnprocessable();

    $otherTenant = Tenant::factory()->create();
    $this->user->tenants()->attach($otherTenant->id, [
        'id' => (string) Str::uuid(),
        'role' => 'employee',
    ]);
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Tenant-Id', $otherTenant->id)
        ->postJson('/api/v1/time-attendance/break-start')->assertUnprocessable();

    $this->assertDatabaseCount('time_entry_events', 0);
});

test('attendance keyed retries replay every operation without changing a later shift', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));

    $clockIn = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [], ['Idempotency-Key' => 'shift-1-in']);
    $clockIn->assertCreated();
    $this->travel(2)->seconds();
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [], [
        'Idempotency-Key' => 'shift-1-in',
        'Origin' => 'https://localhost:4200',
    ])->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')
        ->assertHeader('Access-Control-Expose-Headers', 'Idempotency-Replayed')
        ->assertExactJson($clockIn->json());

    $this->travel(4)->hours();
    $breakStart = apiRequest()->postJson('/api/v1/time-attendance/break-start', [], ['Idempotency-Key' => 'shift-1-break-start']);
    $breakStart->assertCreated();
    apiRequest()->postJson('/api/v1/time-attendance/break-start', [], ['Idempotency-Key' => 'shift-1-break-start'])
        ->assertCreated()->assertExactJson($breakStart->json());

    $this->travel(30)->minutes();
    $breakEnd = apiRequest()->postJson('/api/v1/time-attendance/break-end', [], ['Idempotency-Key' => 'shift-1-break-end']);
    $breakEnd->assertCreated()->assertJsonPath('data.break_minutes', 30);
    apiRequest()->postJson('/api/v1/time-attendance/break-end', [], ['Idempotency-Key' => 'shift-1-break-end'])
        ->assertCreated()->assertExactJson($breakEnd->json());

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [], ['Idempotency-Key' => 'shift-1-break-start'])
        ->assertCreated()->assertExactJson($breakStart->json());

    $this->travel(4)->hours();
    $clockOut = apiRequest()->postJson('/api/v1/time-attendance/clock-out', [], ['Idempotency-Key' => 'shift-1-out']);
    $clockOut->assertOk();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [], ['Idempotency-Key' => 'shift-1-out'])
        ->assertOk()->assertExactJson($clockOut->json());

    $this->travel(2)->hours();
    $newShift = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [], ['Idempotency-Key' => 'shift-2-in']);
    $newShift->assertCreated();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [], ['Idempotency-Key' => 'shift-1-out'])
        ->assertOk()->assertExactJson($clockOut->json());

    $this->assertDatabaseHas('time_entries', ['id' => $newShift->json('data.id'), 'clock_out' => null]);
    $this->assertDatabaseCount('time_entries', 2);
    $this->assertDatabaseCount('time_entry_events', 2);
    $this->assertDatabaseCount('attendance_requests', 5);
});

test('attendance rejects an idempotency key reused for different data or action', function (): void {
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'notes' => 'Original shift',
    ], ['Idempotency-Key' => 'same-key'])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'notes' => 'Changed shift',
    ], ['Idempotency-Key' => 'same-key'])->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [], ['Idempotency-Key' => 'same-key'])->assertConflict();

    $this->assertDatabaseCount('time_entries', 1);
    $this->assertDatabaseCount('time_entry_events', 0);
    $this->assertDatabaseCount('attendance_requests', 1);
});

test('attendance rejects timestamp identities and replays keyed ordinary requests after shift closure', function (): void {
    $requests = [
        ['clock-in', ['clock_in' => '2026-10-15 08:00:00']],
        ['break-start', ['occurred_at' => '2026-10-15 12:00:00']],
        ['break-end', ['occurred_at' => '2026-10-15 12:30:00']],
        ['clock-out', ['clock_out' => '2026-10-15 16:00:00']],
    ];
    $responses = [];

    foreach ($requests as [$action, $payload]) {
        $headers = ['Idempotency-Key' => 'ordinary-'.$action];
        apiRequest()->postJson('/api/v1/time-attendance/'.$action, $payload, $headers)->assertUnprocessable();
        $this->travelTo(Carbon::parse(array_values($payload)[0]));
        $responses[] = apiRequest()->postJson('/api/v1/time-attendance/'.$action, [], $headers)->assertSuccessful()->json();
    }

    foreach ($requests as $index => [$action, $payload]) {
        apiRequest()->postJson('/api/v1/time-attendance/'.$action, [], ['Idempotency-Key' => 'ordinary-'.$action])
            ->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($responses[$index]);
    }

    $this->assertDatabaseCount('time_entries', 1);
    $this->assertDatabaseCount('time_entry_events', 2);
});

test('attendance ordinary timestamps are rejected regardless of timezone representation', function (): void {
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15T08:00:00Z',
    ])->assertUnprocessable()->assertJsonValidationErrors('clock_in');

    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15T10:00:00+02:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('clock_in');

    $this->assertDatabaseCount('time_entries', 0);
});

test('attendance idempotency keys are isolated by user and tenant', function (): void {
    $first = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [], ['Idempotency-Key' => 'device-1']);
    $first->assertCreated();

    $colleague = User::factory()->create();
    $colleague->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'employee']);
    Sanctum::actingAs($colleague);
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [], ['Idempotency-Key' => 'device-1'])
        ->assertCreated()->assertJsonPath('data.user_id', $colleague->id);

    $otherTenant = Tenant::factory()->create();
    $this->user->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'employee']);
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/time-attendance/clock-in', [], [
        'Idempotency-Key' => 'device-1',
        'X-Tenant-Id' => $otherTenant->id,
    ])->assertCreated()->assertJsonPath('data.tenant_id', $otherTenant->id);

    $this->assertDatabaseCount('time_entries', 3);
    $this->assertDatabaseCount('attendance_requests', 3);
});

test('attendance failed operations do not consume an idempotency key', function (): void {
    $payload = [];
    $headers = ['Idempotency-Key' => 'retry-after-error'];
    apiRequest()->postJson('/api/v1/time-attendance/break-start', $payload, $headers)->assertUnprocessable();

    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [], ['Idempotency-Key' => 'retry-in'])->assertCreated();
    apiRequest()->postJson('/api/v1/time-attendance/break-start', $payload, $headers)->assertCreated();

    $this->assertDatabaseCount('time_entry_events', 1);
    $this->assertDatabaseCount('attendance_requests', 2);
});

test('attendance validates idempotency keys on every mutation', function (string $action): void {
    apiRequest()->postJson('/api/v1/time-attendance/'.$action, [], ['Idempotency-Key' => str_repeat('a', 129)])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
})->with(['clock-in', 'clock-out', 'break-start', 'break-end']);

test('closing a time-entry shift syncs its daily totals to the linked HR employee', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));

    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated();
    $this->travel(30)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/break-end')->assertCreated();
    $this->travel(30)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $attendance = AttendanceRecord::where('tenant_id', $this->tenant->id)
        ->where('employee_id', $employee->id)->where('work_date', '2026-10-15')->sole();
    expect($attendance->source)->toBe('time_attendance_api')
        ->and($attendance->clock_in_at->toDateTimeString())->toBe('2026-10-15 08:00:00')
        ->and($attendance->clock_out_at->toDateTimeString())->toBe('2026-10-15 13:00:00')
        ->and($attendance->break_minutes)->toBe(30)
        ->and((float) $attendance->recorded_hours)->toBe(4.5)
        ->and((float) $attendance->regular_hours)->toBe(0.0)
        ->and((float) $attendance->overtime_hours)->toBe(0.0);
});

test('multiple daily shifts aggregate to one HR attendance record without guessing overtime', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));

    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();
    $this->travelTo(now()->setTime(13, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $attendance = AttendanceRecord::where('tenant_id', $this->tenant->id)
        ->where('employee_id', $employee->id)->where('work_date', '2026-10-15')->sole();
    expect($attendance->clock_in_at->toDateTimeString())->toBe('2026-10-15 08:00:00')
        ->and($attendance->clock_out_at->toDateTimeString())->toBe('2026-10-15 17:00:00')
        ->and((float) $attendance->recorded_hours)->toBe(8.0)
        ->and((float) $attendance->regular_hours)->toBe(0.0)
        ->and((float) $attendance->overtime_hours)->toBe(0.0)
        ->and(AttendanceRecord::where('employee_id', $employee->id)->count())->toBe(1);
});

test('time-entry synchronization preserves an HR attendance record from another source', function (): void {
    $employee = Employee::factory()->active()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'user_id' => $this->user->id,
    ]);
    AttendanceRecord::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'employee_id' => $employee->id,
        'work_date' => '2026-10-15',
        'clock_in_at' => '2026-10-15 09:00:00',
        'clock_out_at' => '2026-10-15 17:00:00',
        'break_minutes' => 60,
        'recorded_hours' => 7,
        'regular_hours' => 7,
        'overtime_hours' => 0,
        'status' => 'present',
        'source' => 'web_portal',
    ]);
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));

    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
    $this->travel(8)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/clock-out')->assertOk();

    $attendance = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($attendance->source)->toBe('web_portal')
        ->and($attendance->clock_in_at->toDateTimeString())->toBe('2026-10-15 09:00:00')
        ->and((float) $attendance->recorded_hours)->toBe(7.0);
});

test('attendance correction approval changes effective hours while preserving original timestamps', function (): void {
    $entry = attendanceCorrectionShift();
    $response = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload());
    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.requested_by', $this->user->id);
    $correctionId = $response->json('data.id');

    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'clock_out' => '2026-10-15 16:00:00', 'corrected_clock_out' => null]);
    apiRequest()->getJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections')
        ->assertOk()->assertJsonCount(1, 'data');

    $reviewer = attendanceCorrectionReviewer();
    Sanctum::actingAs($reviewer);
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')
        ->assertOk()->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.reviewed_by', $reviewer->id);

    $this->assertDatabaseHas('time_entries', [
        'id' => $entry->id,
        'clock_out' => '2026-10-15 16:00:00',
        'break_minutes' => 30,
        'corrected_clock_out' => '2026-10-15 17:00:00',
        'corrected_break_minutes' => 60,
    ]);
    Sanctum::actingAs($this->user);
    apiRequest()->getJson('/api/v1/time-attendance/monthly-report?year=2026&month=10')
        ->assertOk()
        ->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 8.0)
        ->assertJsonPath('data.compliance.stats.total_hours', fn ($hours): bool => (float) $hours === 8.0)
        ->assertJsonPath('data.entries.0.clock_out', '2026-10-15T17:00:00+00:00')
        ->assertJsonPath('data.entries.0.original.clock_out', '2026-10-15T16:00:00+00:00')
        ->assertJsonPath('data.entries.0.applied_correction_id', $correctionId);
    apiRequest()->getJson('/api/v1/time-attendance/annual-report?year=2026')
        ->assertOk()->assertJsonPath('data.summary.net_hours_worked', fn ($hours): bool => (float) $hours === 8.0);
});

test('attendance correction rejection requires a reason and does not change the shift', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    Sanctum::actingAs(attendanceCorrectionReviewer());
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/reject')->assertUnprocessable();
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/reject', ['reason' => 'The original timestamps have been verified.'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertConflict();
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'corrected_clock_out' => null]);
});

test('attendance correction cannot be self approved or reviewed without permission', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    Gate::define('approve-attendance-corrections', fn (): bool => true);
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertForbidden();

    $colleague = User::factory()->create();
    $colleague->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'employee']);
    Gate::define('approve-attendance-corrections', fn (): bool => false);
    Sanctum::actingAs($colleague);
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertForbidden();
    apiRequest()->getJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections')->assertForbidden();
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())->assertForbidden();
});

test('attendance correction cannot be self approved through a global gate override', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    Gate::before(fn (): bool => true);
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertForbidden();
    $this->assertDatabaseHas('time_entry_corrections', ['id' => $correctionId, 'status' => 'pending']);
});

test('attendance correction endpoints hide entries and requests from other tenants', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    $otherTenant = Tenant::factory()->create();
    $this->user->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'manager']);
    Gate::define('approve-attendance-corrections', fn (): bool => true);
    $headers = ['X-Tenant-Id' => $otherTenant->id];
    $this->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload(), $headers)->assertNotFound();
    $this->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve', [], $headers)->assertNotFound();
});

test('attendance correction uses the selected tenant for users with multiple memberships', function (): void {
    $otherTenant = Tenant::factory()->create();
    $this->user->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'employee']);
    app(TenantContext::class)->setTenant($otherTenant);
    $entry = TimeEntry::create([
        'tenant_id' => $otherTenant->id, 'user_id' => $this->user->id,
        'clock_in' => '2026-10-15 08:00:00', 'clock_out' => '2026-10-15 16:00:00', 'break_minutes' => 30,
    ]);
    app(TenantContext::class)->clear();
    $headers = ['X-Tenant-Id' => $otherTenant->id];
    $correctionId = $this->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload(), $headers)
        ->assertCreated()->json('data.id');
    $this->getJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', $headers)->assertOk();

    $reviewer = attendanceCorrectionReviewer();
    $reviewer->tenants()->attach($otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'manager']);
    Sanctum::actingAs($reviewer);
    $this->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve', [], $headers)->assertOk();
});

test('attendance correction refuses duplicate pending requests and stale approvals', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())->assertConflict();
    $entry->forceFill(['clock_out' => '2026-10-15 16:30:00'])->save();
    Sanctum::actingAs(attendanceCorrectionReviewer());
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertConflict();
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'clock_out' => '2026-10-15 16:30:00', 'corrected_clock_out' => null]);
});

test('attendance correction validates proposed duration and rejects overlapping shifts', function (): void {
    $entry = attendanceCorrectionShift();
    $payload = attendanceCorrectionPayload();
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', [...$payload, 'reason' => ''])->assertUnprocessable();
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', [...$payload, 'clock_out' => '2026-10-15T07:00:00Z'])->assertUnprocessable();
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', [...$payload, 'break_minutes' => 1000])->assertUnprocessable();
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', [...$payload, 'clock_out' => '2099-01-01T17:00:00Z'])->assertUnprocessable();
    TimeEntry::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'clock_in' => '2026-10-15 16:30:00',
        'clock_out' => '2026-10-15 18:00:00',
    ]);
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', $payload)->assertUnprocessable();
});

test('attendance correction can close a forgotten exit without overwriting its original null value', function (): void {
    $entry = attendanceCorrectionShift();
    $entry->forceFill(['clock_out' => null])->save();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    Sanctum::actingAs(attendanceCorrectionReviewer());
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertOk();
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'clock_out' => null, 'corrected_clock_out' => '2026-10-15 17:00:00']);
    Sanctum::actingAs($this->user);
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();
});

test('attendance correction moves the effective report period without moving the original timestamps', function (): void {
    $entry = attendanceCorrectionShift();
    $entry->forceFill(['clock_in' => '2025-12-31 22:00:00', 'clock_out' => '2026-01-01 02:00:00', 'break_minutes' => 0])->save();
    $payload = [
        ...attendanceCorrectionPayload(),
        'clock_in' => '2026-01-01T00:00:00Z',
        'clock_out' => '2026-01-01T04:00:00Z',
        'break_minutes' => 0,
    ];
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', $payload)
        ->assertCreated()->json('data.id');
    Sanctum::actingAs(attendanceCorrectionReviewer());
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertOk();
    Sanctum::actingAs($this->user);
    apiRequest()->getJson('/api/v1/time-attendance/annual-report?year=2026')
        ->assertOk()->assertJsonPath('data.summary.total_shifts', 1);
    apiRequest()->getJson('/api/v1/time-attendance/monthly-report?year=2026&month=1')
        ->assertOk()->assertJsonPath('data.summary.total_shifts', 1);
    apiRequest()->getJson('/api/v1/time-attendance/annual-report?year=2025')
        ->assertOk()->assertJsonPath('data.summary.total_shifts', 0);
    apiRequest()->getJson('/api/v1/time-attendance/entries?from_date=2026-01-01&to_date=2026-01-01')
        ->assertOk()->assertJsonPath('meta.total', 1);
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'clock_in' => '2025-12-31 22:00:00']);
});

test('attendance correction history preserves prior approvals and audit actors', function (): void {
    $entry = attendanceCorrectionShift();
    $firstId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    $reviewer = attendanceCorrectionReviewer();
    Sanctum::actingAs($reviewer);
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$firstId.'/approve')->assertOk();

    Sanctum::actingAs($this->user);
    $secondId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', [
        ...attendanceCorrectionPayload(), 'clock_out' => '2026-10-15T18:00:00Z',
    ])->assertCreated()->json('data.id');
    Sanctum::actingAs($reviewer);
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$secondId.'/approve')->assertOk();
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$firstId.'/approve')->assertConflict();

    apiRequest()->getJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections')->assertOk()->assertJsonCount(2, 'data');
    $this->assertDatabaseHas('time_entries', [
        'id' => $entry->id, 'clock_out' => '2026-10-15 16:00:00',
        'corrected_clock_out' => '2026-10-15 18:00:00', 'applied_correction_id' => $secondId,
    ]);
    $this->assertDatabaseHas('enterprise_audit_ledger', [
        'resource_id' => $firstId, 'action' => 'ATTENDANCE_CORRECTION_REQUESTED', 'actor_user_id' => $this->user->id,
    ]);
    $this->assertDatabaseHas('enterprise_audit_ledger', [
        'resource_id' => $secondId, 'action' => 'ATTENDANCE_CORRECTION_APPROVED', 'actor_user_id' => $reviewer->id,
    ]);
});

test('attendance correction preserves break events and refuses to exclude their original times', function (): void {
    $entry = attendanceCorrectionShift();
    foreach ([['break_start', '2026-10-15 12:00:00'], ['break_end', '2026-10-15 12:30:00']] as $index => [$type, $time]) {
        $entry->events()->create([
            'sequence' => $index + 1, 'type' => $type, 'occurred_at' => $time,
            'recorded_at' => $time, 'recorded_by' => $this->user->id,
        ]);
    }
    apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', [
        ...attendanceCorrectionPayload(), 'clock_out' => '2026-10-15T11:00:00Z',
    ])->assertUnprocessable();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    Sanctum::actingAs(attendanceCorrectionReviewer());
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertOk();
    $this->assertDatabaseCount('time_entry_events', 2);
    $this->assertDatabaseHas('time_entry_events', ['time_entry_id' => $entry->id, 'sequence' => 1, 'occurred_at' => '2026-10-15 12:00:00']);
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'break_minutes' => 30, 'corrected_break_minutes' => 60]);
});

test('attendance correction rechecks overlaps when a new shift appears before approval', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    TimeEntry::create([
        'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
        'clock_in' => '2026-10-15 16:30:00', 'clock_out' => '2026-10-15 18:00:00',
    ]);
    Sanctum::actingAs(attendanceCorrectionReviewer());
    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertUnprocessable();
    $this->assertDatabaseHas('time_entry_corrections', ['id' => $correctionId, 'status' => 'pending']);
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'corrected_clock_out' => null]);
});

test('attendance correction approval rolls back when audit recording fails', function (): void {
    $entry = attendanceCorrectionShift();
    $correctionId = apiRequest()->postJson('/api/v1/time-attendance/entries/'.$entry->id.'/corrections', attendanceCorrectionPayload())
        ->assertCreated()->json('data.id');
    Sanctum::actingAs(attendanceCorrectionReviewer());
    $ledger = \Mockery::mock(AuditLedgerInterface::class);
    $ledger->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit temporarily unavailable.'));
    app()->instance(AuditLedgerInterface::class, $ledger);

    apiRequest()->postJson('/api/v1/time-attendance/corrections/'.$correctionId.'/approve')->assertInternalServerError();
    $this->assertDatabaseHas('time_entry_corrections', ['id' => $correctionId, 'status' => 'pending', 'reviewed_by' => null]);
    $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'corrected_clock_out' => null, 'applied_correction_id' => null]);
});

// ------------------------------------------------------------------------------------------------
// TreasuryController Endpoints
// ------------------------------------------------------------------------------------------------

test('treasury controller handles bank accounts, auto-reconciliation, and camt/cbi imports', function (): void {
    $bankAccount = BankAccount::create([
        'tenant_id' => $this->tenant->id,
        'bank_name' => 'Intesa Sanpaolo',
        'iban' => 'IT60X0542811101000000123456',
        'currency' => 'EUR',
    ]);

    // 1. List accounts and transactions
    apiRequest()->getJson('/api/v1/treasury/bank-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.bank_name', 'Intesa Sanpaolo');

    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Debtor Client SRL',
        'type' => 'customer',
        'country' => 'IT',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-MATCH-100',
        'date' => now()->toDateString(),
        'total_net' => 200,
        'total_gross' => 244,
        'payment_status' => 'unpaid',
    ]);

    $transaction = BankTransaction::create([
        'tenant_id' => $this->tenant->id,
        'bank_account_id' => $bankAccount->id,
        'booking_date' => now()->toDateString(),
        'value_date' => now()->toDateString(),
        'amount' => 244.00,
        'currency' => 'EUR',
        'description' => 'Payment for INV-MATCH-100',
        'reference' => 'INV-MATCH-100',
        'status' => 'unmatched',
    ]);

    apiRequest()->getJson('/api/v1/treasury/transactions')
        ->assertOk()
        ->assertJsonPath('data.total', 1);

    // 2. Auto-match reconciliation
    $matchResponse = apiRequest()->postJson('/api/v1/treasury/reconciliation/auto-match');
    $matchResponse->assertOk()
        ->assertJsonPath('data.matched_invoices', 1);

    expect($transaction->fresh()->status)->toBe('matched')
        ->and($invoice->fresh()->payment_status)->toBe('paid');

    // 3. CAMT.053 XML import
    $invalidCamt = UploadedFile::fake()->createWithContent('invalid.xml', 'Not CAMT XML');
    apiRequest()->postJson('/api/v1/treasury/import-camt', [
        'camt_file' => $invalidCamt,
        'bank_account_id' => $bankAccount->id,
    ])->assertStatus(422);

    $camtXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Ntry>
        <Amt Ccy="EUR">550.00</Amt>
        <Dt>2026-10-31</Dt>
        <Ref>CAMT-REF-01</Ref>
        <Ustrd>Direct Wire Transfer</Ustrd>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;

    $validCamt = UploadedFile::fake()->createWithContent('statement.xml', $camtXml);
    apiRequest()->postJson('/api/v1/treasury/import-camt', [
        'camt_file' => $validCamt,
        'bank_account_id' => $bankAccount->id,
    ])->assertOk()->assertJsonPath('data.imported_records', 1);

    // 4. CBI import
    $cbiFile = UploadedFile::fake()->create('cbi.txt', 256);
    apiRequest()->postJson('/api/v1/treasury/import-cbi', [
        'cbi_file' => $cbiFile,
        'bank_account_id' => $bankAccount->id,
    ])->assertOk()->assertJsonPath('data.status', 'parsed');
});
