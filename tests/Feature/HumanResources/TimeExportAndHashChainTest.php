<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Services\TimeTracking\HashChainIntegrityService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Export SA', 'legal_name' => 'Export SA', 'country' => 'CH', 'default_currency' => 'CHF']);

    $this->manager = tehMember($this->tenant, 'admin');
    $this->employee = tehMember($this->tenant, 'member');
});

function tehMember(Tenant $tenant, string $role): User
{
    $user = User::create([
        'name' => ucfirst($role).' '.Str::random(4),
        'email' => Str::random(10).'@example.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function tehApi(User $user, ?Tenant $tenant = null)
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

// ---------------------------------------------------------------------------
// Hash chain integrity
// ---------------------------------------------------------------------------

test('a fresh tenant has an empty but verified hash chain', function (): void {
    tehApi($this->manager)->getJson('/api/v1/hr/time/verify-integrity')
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.entries_checked', 0)
        ->assertJsonPath('data.broken_at_id', null);
});

test('the hash chain grows and verifies after clock events are written', function (): void {
    // Clock in then clock out via the API; each call appends a TimeAuditEntry with a hash.
    tehApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])
        ->assertCreated();

    tehApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'Out'])
        ->assertCreated();

    tehApi($this->manager)->getJson('/api/v1/hr/time/verify-integrity')
        ->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.entries_checked', 2)
        ->assertJsonPath('data.broken_at_id', null);
});

test('direct DB tampering of an audit entry breaks the sha-256 chain', function (): void {
    tehApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])->assertCreated();
    tehApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'Out'])->assertCreated();

    // Bypass the immutable model guard with a raw query to tamper with the first entry.
    $entry = DB::table('time_audit_entries')
        ->where('tenant_id', $this->tenant->id)
        ->orderBy('occurred_at')
        ->first();

    DB::table('time_audit_entries')
        ->where('id', $entry->id)
        ->update(['action' => 'Tampered']);

    $result = tehApi($this->manager)->getJson('/api/v1/hr/time/verify-integrity')
        ->assertOk()
        ->assertJsonPath('data.verified', false)
        ->json('data');

    expect($result['broken_at_id'])->not->toBeNull()
        ->and($result['entries_checked'])->toBe(0); // Breaks on the very first entry.
});

test('the immutable audit entry model refuses updates and deletes', function (): void {
    tehApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])->assertCreated();

    $entry = TimeAuditEntry::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->first();

    expect(fn () => $entry->update(['action' => 'Modified']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => TimeAuditEntry::withoutGlobalScopes()->whereKey($entry->id)->update(['action' => 'BulkModified']))->toThrow(LogicException::class);

    expect(TimeAuditEntry::withoutGlobalScopes()->whereKey($entry->id)->value('action'))->toBe('Created');
});

test('the hash chain service computes consistent hashes for the same payload', function (): void {
    $service = app(HashChainIntegrityService::class);

    $payload = [
        'tenant_id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'entity_type' => 'TimeClockEvent',
        'entity_id' => (string) Str::uuid(),
        'action' => 'Created',
        'before_values' => null,
        'after_values' => ['event_type' => 'In'],
        'actor_id' => (string) Str::uuid(),
        'occurred_at' => '2026-11-06 10:00:00',
    ];

    $hash1 = $service->computeHash($payload, null);
    $hash2 = $service->computeHash($payload, null);

    expect($hash1)->toBe($hash2)
        ->and(strlen($hash1))->toBe(64); // SHA-256 hex string
});

test('changing any payload field produces a different hash', function (): void {
    $service = app(HashChainIntegrityService::class);

    $payload = [
        'tenant_id' => (string) Str::uuid(),
        'user_id' => (string) Str::uuid(),
        'entity_type' => 'TimeClockEvent',
        'entity_id' => (string) Str::uuid(),
        'action' => 'Created',
        'before_values' => null,
        'after_values' => ['event_type' => 'In'],
        'actor_id' => (string) Str::uuid(),
        'occurred_at' => '2026-11-06 10:00:00',
    ];

    $original = $service->computeHash($payload, null);
    $tampered = $service->computeHash([...$payload, 'action' => 'Tampered'], null);

    expect($original)->not->toBe($tampered);
});

// ---------------------------------------------------------------------------
// IT LUL CSV export
// ---------------------------------------------------------------------------

test('the lul export returns utf-8 csv with correct italian headers', function (): void {
    TimeDailyRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->employee->id,
        'work_date' => '2026-11-02',
        'worked_minutes' => 450, // 7h30
        'start_time' => '2026-11-02 07:00:00',
        'end_time' => '2026-11-02 15:30:00',
    ]);

    $response = tehApi($this->manager)->getJson(
        '/api/v1/hr/time/export?format=lul_csv&from=2026-11-01&to=2026-11-30&jurisdiction=IT'
    )->assertOk();

    $content = $response->content();

    expect($content)->toContain('MATRICOLA')
        ->and($content)->toContain('COGNOME_NOME')
        ->and($content)->toContain('ORA_INIZIO')
        ->and($content)->toContain('ORE_LAVORATE')
        ->and($content)->toContain('02/11/2026')
        ->and($response->headers->get('Content-Type'))->toContain('text/csv');
});

test('the lul export is restricted to managers', function (): void {
    tehApi($this->employee)->getJson(
        '/api/v1/hr/time/export?format=lul_csv&from=2026-11-01&to=2026-11-30&jurisdiction=IT'
    )->assertForbidden();
});

test('the lul export can be scoped to a single employee', function (): void {
    $other = tehMember($this->tenant, 'member');

    TimeDailyRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->employee->id,
        'work_date' => '2026-11-03',
        'worked_minutes' => 480,
    ]);

    TimeDailyRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $other->id,
        'work_date' => '2026-11-03',
        'worked_minutes' => 480,
    ]);

    $allContent = tehApi($this->manager)->getJson(
        '/api/v1/hr/time/export?format=lul_csv&from=2026-11-01&to=2026-11-30&jurisdiction=IT'
    )->assertOk()->content();

    $filteredContent = tehApi($this->manager)->getJson(
        "/api/v1/hr/time/export?format=lul_csv&from=2026-11-01&to=2026-11-30&jurisdiction=IT&employee_id={$this->employee->id}"
    )->assertOk()->content();

    // The filtered export has exactly 2 lines (header + 1 record) while the full has 3.
    expect(count(array_filter(explode("\n", $filteredContent))))->toBe(2)
        ->and(count(array_filter(explode("\n", $allContent))))->toBe(3);
});

// ---------------------------------------------------------------------------
// CH SECO CSV export
// ---------------------------------------------------------------------------

test('the seco export returns utf-8-bom csv with swiss headers', function (): void {
    TimeDailyRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->employee->id,
        'work_date' => '2026-11-04',
        'worked_minutes' => 480, // 8h
        'start_time' => '2026-11-04 07:00:00',
        'end_time' => '2026-11-04 15:30:00',
    ]);

    $response = tehApi($this->manager)->getJson(
        '/api/v1/hr/time/export?format=seco_csv&from=2026-11-01&to=2026-11-30&jurisdiction=CH'
    )->assertOk();

    $content = $response->content();

    // UTF-8 BOM at start (EF BB BF).
    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($content)->toContain('Mitarbeiter-Nr')
        ->and($content)->toContain('Arbeitsbeginn')
        ->and($content)->toContain('Arbeitszeit-Min')
        ->and($content)->toContain('04.11.2026')
        ->and($response->headers->get('Content-Type'))->toContain('text/csv');
});

test('the seco export calculates break minutes from gross minus worked', function (): void {
    TimeDailyRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->employee->id,
        'work_date' => '2026-11-05',
        'worked_minutes' => 450, // 7h30, gross 8h => 30 min break
        'start_time' => '2026-11-05 07:00:00',
        'end_time' => '2026-11-05 15:00:00', // 8h gross
    ]);

    $content = tehApi($this->manager)->getJson(
        '/api/v1/hr/time/export?format=seco_csv&from=2026-11-01&to=2026-11-30&jurisdiction=CH'
    )->assertOk()->content();

    // The break column (index 5) should be 30 (480-450).
    $lines = array_values(array_filter(explode("\n", $content)));
    $dataRow = str_replace("\r", '', $lines[1]); // skip BOM header line
    $cols = explode(';', $dataRow);

    expect((int) $cols[5])->toBe(30) // Pausen-Min
        ->and((int) $cols[6])->toBe(450); // Arbeitszeit-Min
});

test('the seco export is restricted to managers', function (): void {
    tehApi($this->employee)->getJson(
        '/api/v1/hr/time/export?format=seco_csv&from=2026-11-01&to=2026-11-30&jurisdiction=CH'
    )->assertForbidden();
});

test('the export endpoint rejects an invalid format', function (): void {
    tehApi($this->manager)->getJson(
        '/api/v1/hr/time/export?format=unknown&from=2026-11-01&to=2026-11-30&jurisdiction=CH'
    )->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// IT D.Lgs. 66/2003 — 11 h daily rest (Art. 7)
// ---------------------------------------------------------------------------

test('the compliance alerts endpoint flags a rest period shorter than 11 hours', function (): void {
    // Manager records: Out at 22:00, then In at 06:00 the following day — only 8 h of rest.
    tehApi($this->manager)->postJson('/api/v1/hr/time/events', [
        'employee_id' => $this->employee->id,
        'event_type' => 'Out',
        'occurred_at' => '2026-11-02 22:00:00',
        'reason' => 'Registered for compliance test',
    ])->assertCreated();

    tehApi($this->manager)->postJson('/api/v1/hr/time/events', [
        'employee_id' => $this->employee->id,
        'event_type' => 'In',
        'occurred_at' => '2026-11-03 06:00:00',
        'reason' => 'Registered for compliance test',
    ])->assertCreated();

    $response = tehApi($this->manager)->getJson(
        "/api/v1/hr/time/compliance-alerts?jurisdiction=IT&from=2026-11-01&to=2026-11-30&employee_id={$this->employee->id}"
    )->assertOk();

    $alerts = $response->json('data');

    expect(array_filter($alerts, static fn (array $a): bool => $a['type'] === 'rest_11h'))->not->toBeEmpty()
        ->and($alerts[0]['actual_hours'])->toBeLessThan(11.0);
});

// ---------------------------------------------------------------------------
// CH OLL 1 Art. 73a (Waiver) and Art. 73b (Simplified) — profile validation
// ---------------------------------------------------------------------------

test('saving a waiver profile without a collective_agreement_reference is rejected', function (): void {
    tehApi($this->manager)->putJson("/api/v1/hr/time/profiles/{$this->employee->id}", [
        'mode' => 'Waived',
        'agreement_date' => '2026-01-01',
        'agreement_valid_from' => '2026-01-01',
        'annual_gross_salary' => 130000,
        'legal_max_weekly_hours' => 45,
        'weekly_hours' => 42,
        'work_days_per_week' => 5,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['collective_agreement_reference']);
});

test('saving a simplified profile without an agreement_date is rejected', function (): void {
    tehApi($this->manager)->putJson("/api/v1/hr/time/profiles/{$this->employee->id}", [
        'mode' => 'Simplified',
        'agreement_basis' => 'collective',
        'autonomy_percent' => 25,
        'agreement_valid_from' => '2026-01-01',
        'legal_max_weekly_hours' => 45,
        'weekly_hours' => 42,
        'work_days_per_week' => 5,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['agreement_date']);
});

// ---------------------------------------------------------------------------
// Retention lock — GDPR / LUL / Art. 73 OLL 1
// ---------------------------------------------------------------------------

test('force-deleting a recent clock event is blocked by the retention lock', function (): void {
    tehApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])
        ->assertCreated();

    $event = TimeClockEvent::query()
        ->where('user_id', $this->employee->id)
        ->first();

    expect(fn () => $event->forceDelete())->toThrow(LogicException::class);
});

test('force-deleting a recent daily record is blocked by the retention lock', function (): void {
    $record = TimeDailyRecord::factory()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->employee->id,
        'work_date' => '2026-11-02',
        'worked_minutes' => 480,
    ]);

    expect(fn () => $record->forceDelete())->toThrow(LogicException::class);
});
