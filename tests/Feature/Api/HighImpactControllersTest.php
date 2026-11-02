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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

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
    // 1. Clock in
    $clockInResponse = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => now()->format('Y-m-d H:i:s'),
        'latitude' => 45.4642,
        'longitude' => 9.1900,
        'notes' => 'Morning shift',
    ]);
    $clockInResponse->assertCreated();

    // 2. Clock in again while active shift exists -> 409 Conflict
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(409);

    // 3. Clock out with invalid timestamp before clock in
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->subHours(2)->format('Y-m-d H:i:s'),
    ])->assertStatus(422);

    // 4. Clock out successfully
    $clockOutResponse = apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->addHours(8)->format('Y-m-d H:i:s'),
        'break_minutes' => 45,
        'notes' => 'Completed shift',
    ]);
    $clockOutResponse->assertOk();

    // 5. Clock out when no active shift exists -> 422
    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => now()->format('Y-m-d H:i:s'),
    ])->assertStatus(422);

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

test('attendance report permits an explicitly selected personal report', function (string $period): void {
    apiRequest()->getJson('/api/v1/time-attendance/'.$period.'-report?user_id='.$this->user->id)
        ->assertOk()
        ->assertJsonPath('data.user.id', $this->user->id);
})->with(['monthly', 'annual']);

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

test('attendance records multiple break events and derives minutes for reports', function (): void {
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15 08:00:00',
    ])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 12:00:15',
    ])->assertCreated()
        ->assertJsonPath('data.is_on_break', true)
        ->assertJsonPath('data.events.0.type', 'break_start')
        ->assertJsonPath('data.events.0.recorded_by', $this->user->id);

    apiRequest()->postJson('/api/v1/time-attendance/break-end', [
        'occurred_at' => '2026-10-15 12:30:45',
    ])->assertCreated()
        ->assertJsonPath('data.is_on_break', false)
        ->assertJsonPath('data.break_minutes', 30);

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 15:00:00',
    ])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-end', [
        'occurred_at' => '2026-10-15 15:15:30',
    ])->assertCreated()
        ->assertJsonPath('data.break_minutes', 46)
        ->assertJsonCount(4, 'data.events')
        ->assertJsonPath('data.events.3.sequence', 4);

    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => '2026-10-15 16:00:00',
    ])->assertOk()
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
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15 08:00:00',
    ])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-end', [
        'occurred_at' => '2026-10-15 09:00:00',
    ])->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 07:00:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => 'invalid',
    ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 12:00:00',
    ])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 12:05:00',
    ])->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => '2026-10-15 16:00:00',
    ])->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/break-end', [
        'occurred_at' => '2026-10-15 11:59:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    apiRequest()->postJson('/api/v1/time-attendance/break-end', [
        'occurred_at' => '2026-10-15 12:30:00',
    ])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/break-end', [
        'occurred_at' => '2026-10-15 12:35:00',
    ])->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 12:15:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');

    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => '2026-10-15 12:15:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('clock_out');

    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => '2026-10-15 16:00:00',
        'break_minutes' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('break_minutes');

    apiRequest()->postJson('/api/v1/time-attendance/clock-out', [
        'clock_out' => '2026-10-15 16:00:00',
    ])->assertOk()->assertJsonPath('data.break_minutes', 30);

    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertUnprocessable();
    $this->assertDatabaseCount('time_entry_events', 2);
});

test('attendance break events require an active shift', function (string $action): void {
    apiRequest()->postJson('/api/v1/time-attendance/'.$action)->assertUnprocessable();
})->with(['break-start', 'break-end']);

test('attendance break events default to server time', function (): void {
    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(8, 0));
    apiRequest()->postJson('/api/v1/time-attendance/clock-in')->assertCreated();

    $this->travel(4)->hours();
    apiRequest()->postJson('/api/v1/time-attendance/break-start')->assertCreated();

    $this->travel(30)->minutes();
    apiRequest()->postJson('/api/v1/time-attendance/break-end')
        ->assertCreated()->assertJsonPath('data.break_minutes', 30);
});

test('attendance break events cannot act on another user or tenant shift', function (): void {
    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15 08:00:00',
    ])->assertCreated();

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
        'clock_in' => '2026-10-15 08:00:00',
    ], ['Idempotency-Key' => 'same-key'])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15 09:00:00',
    ], ['Idempotency-Key' => 'same-key'])->assertConflict();

    apiRequest()->postJson('/api/v1/time-attendance/break-start', [
        'occurred_at' => '2026-10-15 12:00:00',
    ], ['Idempotency-Key' => 'same-key'])->assertConflict();

    $this->assertDatabaseCount('time_entries', 1);
    $this->assertDatabaseCount('time_entry_events', 0);
    $this->assertDatabaseCount('attendance_requests', 1);
});

test('attendance automatically deduplicates explicit timestamps after the shift is closed', function (): void {
    $requests = [
        ['clock-in', ['clock_in' => '2026-10-15 08:00:00']],
        ['break-start', ['occurred_at' => '2026-10-15 12:00:00']],
        ['break-end', ['occurred_at' => '2026-10-15 12:30:00']],
        ['clock-out', ['clock_out' => '2026-10-15 16:00:00']],
    ];
    $responses = [];

    foreach ($requests as [$action, $payload]) {
        $responses[] = apiRequest()->postJson('/api/v1/time-attendance/'.$action, $payload)->assertSuccessful()->json();
    }

    foreach ($requests as $index => [$action, $payload]) {
        apiRequest()->postJson('/api/v1/time-attendance/'.$action, $payload)
            ->assertSuccessful()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($responses[$index]);
    }

    $this->assertDatabaseCount('time_entries', 1);
    $this->assertDatabaseCount('time_entry_events', 2);
});

test('attendance deduplication normalizes equivalent timestamp offsets', function (): void {
    $original = apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15T08:00:00Z',
    ])->assertCreated();

    apiRequest()->postJson('/api/v1/time-attendance/clock-in', [
        'clock_in' => '2026-10-15T10:00:00+02:00',
    ])->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($original->json());

    $this->assertDatabaseCount('time_entries', 1);
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
    $payload = ['occurred_at' => '2026-10-15 12:00:00'];
    $headers = ['Idempotency-Key' => 'retry-after-error'];
    apiRequest()->postJson('/api/v1/time-attendance/break-start', $payload, $headers)->assertUnprocessable();

    apiRequest()->postJson('/api/v1/time-attendance/clock-in', ['clock_in' => '2026-10-15 08:00:00'])->assertCreated();
    apiRequest()->postJson('/api/v1/time-attendance/break-start', $payload, $headers)->assertCreated();

    $this->assertDatabaseCount('time_entry_events', 1);
    $this->assertDatabaseCount('attendance_requests', 2);
});

test('attendance validates idempotency keys on every mutation', function (string $action): void {
    apiRequest()->postJson('/api/v1/time-attendance/'.$action, [], ['Idempotency-Key' => str_repeat('a', 129)])
        ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
})->with(['clock-in', 'clock-out', 'break-start', 'break-end']);

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
