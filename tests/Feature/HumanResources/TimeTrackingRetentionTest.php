<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Backup\TenantBackupService;
use App\Services\Backup\TenantRestoreService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Rossi SA', 'legal_name' => 'Rossi SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->otherTenant = Tenant::create(['name' => 'Bianchi SA', 'legal_name' => 'Bianchi SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->employeeId = (string) Str::uuid7();
});

function trtAudit(Tenant $tenant, string $occurredAt, ?string $employeeId = null): TimeAuditEntry
{
    return app(TenantContext::class)->runWithTenant($tenant, fn (): TimeAuditEntry => TimeAuditEntry::create([
        'user_id' => $employeeId,
        'entity_type' => 'TimeClockEvent',
        'action' => 'Created',
        'after_values' => ['event_type' => 'In'],
        'actor_name' => 'Anna',
        'occurred_at' => $occurredAt,
    ]));
}

function trtEvent(Tenant $tenant, string $occurredAt): TimeClockEvent
{
    return TimeClockEvent::factory()->create(['tenant_id' => $tenant->id, 'user_id' => test()->employeeId, 'occurred_at' => $occurredAt]);
}

function trtRecord(Tenant $tenant, string $workDate): TimeDailyRecord
{
    return TimeDailyRecord::factory()->create(['tenant_id' => $tenant->id, 'user_id' => test()->employeeId, 'work_date' => $workDate]);
}

/**
 * @return array{events: int, records: int, audit: int}
 */
function trtCounts(Tenant $tenant): array
{
    return [
        'events' => DB::table('time_clock_events')->where('tenant_id', $tenant->id)->count(),
        'records' => DB::table('time_daily_records')->where('tenant_id', $tenant->id)->count(),
        'audit' => DB::table('time_audit_entries')->where('tenant_id', $tenant->id)->where('action', '!=', 'RetentionPurge')->count(),
    ];
}

function trtPreference(Tenant $tenant, int $years): void
{
    $settings = $tenant->settings ?? [];
    $settings['preferences']['time_tracking']['retention_years'] = $years;
    $tenant->update(['settings' => $settings]);
}

test('expired records are deleted, logically deleted ones too, and the purge is logged', function (): void {
    trtEvent($this->tenant, '2020-12-31 23:00:00');
    trtEvent($this->tenant, '2021-01-01 00:00:00');
    trtEvent($this->tenant, '2019-03-04 08:00:00')->delete();
    trtRecord($this->tenant, '2020-12-31');
    trtRecord($this->tenant, '2021-01-01');
    trtAudit($this->tenant, '2020-06-01 08:00:00');
    trtAudit($this->tenant, '2024-06-01 08:00:00');

    $this->artisan('time:purge-expired')->assertSuccessful();

    $entry = TimeAuditEntry::withoutGlobalScopes()->where('action', 'RetentionPurge')->sole();

    expect(trtCounts($this->tenant))->toBe(['events' => 1, 'records' => 1, 'audit' => 1])
        ->and($entry->tenant_id)->toBe($this->tenant->id)
        ->and($entry->user_id)->toBeNull()
        ->and($entry->actor_name)->toBe('Sistema')
        ->and($entry->after_values)->toEqual(['cutoff' => '2021-01-01', 'clock_events' => 2, 'daily_records' => 1, 'audit_entries' => 1])
        ->and($entry->reason)->toContain('art. 73 cpv. 2 OLL 1');
});

test('the cutoff is the local midnight of New Year, not the UTC one', function (): void {
    // 23:30 UTC on 31.12.2020 is already 00:30 on 1.1.2021 in Zurich, so it must be kept.
    trtEvent($this->tenant, '2020-12-31T23:30:00Z');
    trtEvent($this->tenant, '2020-12-31T22:30:00Z');

    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(trtCounts($this->tenant)['events'])->toBe(1);
});

test('nothing is logged when nothing expired', function (): void {
    trtEvent($this->tenant, '2024-03-04 08:00:00');

    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(TimeAuditEntry::withoutGlobalScopes()->count())->toBe(0);
});

test('the retention preference of each company applies, never below the minimum', function (): void {
    trtPreference($this->tenant, 10);
    trtPreference($this->otherTenant, 2);

    foreach ([$this->tenant, $this->otherTenant] as $tenant) {
        trtEvent($tenant, '2015-06-01 08:00:00');
        trtEvent($tenant, '2019-06-01 08:00:00');
        trtEvent($tenant, '2023-06-01 08:00:00');
    }

    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(trtCounts($this->tenant)['events'])->toBe(2)
        ->and(trtCounts($this->otherTenant)['events'])->toBe(1);
});

test('the preference is read through the settings API with the legal limits', function (): void {
    $user = User::create(['name' => 'Anna', 'email' => 'anna@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);
    $api = $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $api->putJson('/api/v1/settings/preferences/time_tracking', ['retention_years' => 4])->assertUnprocessable();
    $api->putJson('/api/v1/settings/preferences/time_tracking', ['retention_years' => 31])->assertUnprocessable();
    $api->putJson('/api/v1/settings/preferences/time_tracking', ['retention_years' => 12])->assertOk();

    $years = app(TenantContext::class)->runWithTenant($this->tenant->refresh(), fn (): mixed => app(TenantPreferences::class)->get('time_tracking', 'retention_years'));

    expect($years)->toBe(12);
});

test('a tenant under maintenance is skipped', function (): void {
    trtEvent($this->tenant, '2019-06-01 08:00:00');
    $this->tenant->forceFill(['is_maintenance' => true])->save();

    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(trtCounts($this->tenant)['events'])->toBe(1);
});

test('the history refuses changes and deletions through the model', function (): void {
    $entry = trtAudit($this->tenant, '2019-06-01 08:00:00');

    expect(fn () => $entry->update(['reason' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class)
        ->and(fn () => TimeAuditEntry::withoutGlobalScopes()->whereKey($entry->id)->update(['reason' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => TimeAuditEntry::withoutGlobalScopes()->whereKey($entry->id)->delete())->toThrow(LogicException::class)
        ->and(TimeAuditEntry::withoutGlobalScopes()->whereKey($entry->id)->sole()->reason)->toBeNull();
});

test('mass changes to the history are refused through the model', function (): void {
    $entry = trtAudit($this->tenant, '2019-06-01 08:00:00');

    expect(fn () => TimeAuditEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->update(['reason' => 'Changed']))->toThrow(LogicException::class)
        ->and(fn () => TimeAuditEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->delete())->toThrow(LogicException::class)
        ->and(TimeAuditEntry::withoutGlobalScopes()->whereKey($entry->id)->exists())->toBeTrue();
});

test('the purge deletes only the entries past the legal retention, never the recent ones', function (): void {
    $recent = trtAudit($this->tenant, '2021-06-01 08:00:00');
    $expired = trtAudit($this->tenant, '2019-06-01 08:00:00');

    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(TimeAuditEntry::withoutGlobalScopes()->whereKey($expired->id)->exists())->toBeFalse()
        ->and(TimeAuditEntry::withoutGlobalScopes()->whereKey($recent->id)->exists())->toBeTrue();
});

test('the purge does not unlock the history', function (): void {
    trtAudit($this->tenant, '2019-06-01 08:00:00');
    $kept = trtAudit($this->otherTenant, '2019-06-01 08:00:00');

    $this->otherTenant->forceFill(['is_maintenance' => true])->save();
    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(fn () => $kept->delete())->toThrow(LogicException::class)
        ->and(fn () => TimeAuditEntry::withoutGlobalScopes()->whereKey($kept->id)->delete())->toThrow(LogicException::class)
        ->and(TimeAuditEntry::withoutGlobalScopes()->whereKey($kept->id)->exists())->toBeTrue();
});

test('a restore from backup keeps the immutable history and brings back the records', function (): void {
    Storage::fake('private');
    Storage::fake('local');

    $user = User::create(['name' => 'Anna', 'email' => 'anna@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $event = trtEvent($this->tenant, '2024-06-03 08:00:00');
    $before = trtAudit($this->tenant, '2024-06-03 08:00:00', $this->employeeId);

    $backups = app(TenantBackupService::class);
    $backup = $backups->generate($backups->create($this->tenant, $user))->refresh();

    TimeClockEvent::withoutGlobalScopes()->whereKey($event->id)->update(['note' => 'After the backup']);
    $after = trtAudit($this->tenant, '2024-06-04 08:00:00', $this->employeeId);

    app(TenantRestoreService::class)->restoreBackup($backup, $this->tenant);

    expect(DB::table('time_clock_events')->where('id', $event->id)->value('note'))->toBeNull()
        ->and(DB::table('time_audit_entries')->whereIn('id', [$before->id, $after->id])->count())->toBe(2);
});
