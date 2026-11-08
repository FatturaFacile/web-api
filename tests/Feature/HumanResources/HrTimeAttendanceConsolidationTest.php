<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;

uses(RefreshDatabase::class);

function timeMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function timeAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Orari SA', 'legal_name' => 'Orari SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->hr = timeMember($this->tenant, 'admin');
    $this->worker = timeMember($this->tenant);
});

test('the deprecated clock-in and clock-out write the Human Resources clock events too', function (): void {
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/time-attendance/clock-in', ['clock_in' => '2026-11-06 07:00:00', 'notes' => 'Turno mattina'])
        ->assertCreated()
        ->assertHeader('X-Deprecated', 'true')
        ->assertHeader('Link', '</api/v1/hr/time/events>; rel="successor-version"');
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/time-attendance/clock-in')->assertConflict();
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/time-attendance/clock-out', ['clock_out' => '2026-11-06 06:00:00'])->assertUnprocessable();
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/time-attendance/clock-out', ['clock_out' => '2026-11-06 09:30:00', 'break_minutes' => 15])->assertOk();

    $entry = TimeEntry::withoutGlobalScopes()->where('user_id', $this->worker->id)->sole();
    $events = TimeClockEvent::withoutGlobalScopes()->where('legacy_time_entry_id', $entry->id)->orderBy('occurred_at')->get();

    expect($events->map(fn (TimeClockEvent $event): string => $event->event_type->value)->all())->toBe(['In', 'Out'])
        ->and($events->first()->occurred_at->format('Y-m-d H:i'))->toBe('2026-11-06 07:00')
        ->and($events->last()->note)->toContain('15 min')
        ->and(TimeAuditEntry::query()->withoutGlobalScopes()->where('entity_type', 'TimeClockEvent')->whereIn('entity_id', $events->pluck('id'))->count())->toBe(2);

    timeAs($this->worker, $this->tenant)->getJson('/api/v1/hr/time/events?from=2026-11-06&to=2026-11-06')->assertOk()->assertJsonCount(2, 'data');
});

test('the data migration copies legacy entries once, without touching them', function (): void {
    $closed = TimeEntry::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'clock_in' => '2026-10-01 07:00:00', 'clock_out' => '2026-10-01 16:00:00', 'break_minutes' => 30]);
    $open = TimeEntry::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'clock_in' => '2026-10-02 07:00:00']);
    $migration = require database_path('migrations/2027_04_19_000200_copy_legacy_time_entries_to_clock_events.php');

    $migration->up();
    $migration->up();

    expect(TimeClockEvent::withoutGlobalScopes()->where('legacy_time_entry_id', $closed->id)->count())->toBe(2)
        ->and(TimeClockEvent::withoutGlobalScopes()->where('legacy_time_entry_id', $open->id)->pluck('event_type')->map(fn ($type): string => $type->value)->all())->toBe(['In'])
        ->and(TimeClockEvent::withoutGlobalScopes()->where('legacy_time_entry_id', $closed->id)->where('event_type', 'Out')->sole()->note)->toContain('30 min')
        ->and(TimeAuditEntry::query()->withoutGlobalScopes()->where('reason', 'Consolidamento dei dati di /time-attendance')->count())->toBe(3)
        ->and(DB::table('time_entries')->count())->toBe(2);
});

test('a closed month refuses changes from both APIs until it is reopened with a reason', function (): void {
    $event = TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'occurred_at' => '2026-10-15 07:00:00', 'event_type' => 'In']);
    $hr = fn () => timeAs($this->hr, $this->tenant);

    $hr()->postJson('/api/v1/hr/time-closings', ['year' => 2026, 'month' => 13])->assertUnprocessable()->assertJsonValidationErrors('month');
    $closing = $hr()->postJson('/api/v1/hr/time-closings', ['year' => 2026, 'month' => 10])->assertCreated()->assertJsonPath('data.status', 'closed')->json('data.id');
    $hr()->postJson('/api/v1/hr/time-closings', ['year' => 2026, 'month' => 10])->assertConflict();

    $hr()->patchJson("/api/v1/hr/time/events/{$event->id}", ['occurred_at' => '2026-10-15T07:30:00', 'reason' => 'Correzione'])->assertConflict();
    $hr()->deleteJson("/api/v1/hr/time/events/{$event->id}", ['reason' => 'Errore'])->assertConflict();
    $hr()->postJson('/api/v1/hr/time/events', ['employee_id' => $this->worker->id, 'occurred_at' => '2026-10-20T08:00:00', 'event_type' => 'In', 'reason' => 'Dimenticata'])->assertConflict();
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/time-attendance/clock-in', ['clock_in' => '2026-10-21 07:00:00'])->assertConflict();
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/hr/time-closings/'.$closing.'/reopen', ['reason' => 'Correzione'])->assertForbidden();

    $hr()->postJson("/api/v1/hr/time-closings/{$closing}/reopen", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $hr()->postJson("/api/v1/hr/time-closings/{$closing}/reopen", ['reason' => 'Timbratura mancante'])->assertOk()->assertJsonPath('data.status', 'reopened')->assertJsonPath('data.reopen_count', 1);
    $hr()->patchJson("/api/v1/hr/time/events/{$event->id}", ['occurred_at' => '2026-10-15T07:30:00', 'reason' => 'Correzione'])->assertOk();

    expect(TimeAuditEntry::query()->withoutGlobalScopes()->where('entity_type', 'TimePeriodClosing')->pluck('action')->all())->toBe(['Closed', 'Reopened']);
    timeAs(timeMember($this->other, 'admin'), $this->other)->postJson("/api/v1/hr/time-closings/{$closing}/reopen", ['reason' => 'Straniero'])->assertNotFound();
});

test('overtime follows the tiered company rules and is visible to the employee only for the own records', function (): void {
    foreach ([['2026-11-02 07:00:00', 'In'], ['2026-11-02 12:00:00', 'BreakStart'], ['2026-11-02 12:30:00', 'BreakEnd'], ['2026-11-02 18:30:00', 'Out']] as [$at, $type]) {
        TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'occurred_at' => $at, 'event_type' => $type]);
    }

    $worked = timeAs($this->hr, $this->tenant)->getJson("/api/v1/hr/time/report?employee_id={$this->worker->id}&from=2026-11-02&to=2026-11-02")->assertOk()->json('data.days.0.worked_minutes');
    $none = timeAs($this->worker, $this->tenant)->getJson('/api/v1/hr/time/overtime?from=2026-11-02&to=2026-11-02')->assertOk();
    expect($none->json('data.totals'))->toBe([]);

    $api = timeAs($this->hr, $this->tenant);
    $api->postJson('/api/v1/hr/overtime-rules', ['code' => 'D1', 'name' => 'Straordinario', 'basis' => 'daily', 'threshold_minutes' => 480, 'premium_percent' => '25', 'wage_type_code' => '1500'])->assertCreated();
    $api->postJson('/api/v1/hr/overtime-rules', ['code' => 'D2', 'name' => 'Straordinario oltre 10h', 'basis' => 'daily', 'threshold_minutes' => 600, 'premium_percent' => '50'])->assertCreated();
    $api->postJson('/api/v1/hr/overtime-rules', ['code' => 'D1', 'name' => 'Doppione', 'basis' => 'hourly', 'threshold_minutes' => -1, 'premium_percent' => 'x'])
        ->assertUnprocessable()->assertJsonValidationErrors(['code', 'basis', 'threshold_minutes', 'premium_percent']);

    $totals = collect(timeAs($this->worker, $this->tenant)->getJson('/api/v1/hr/time/overtime?from=2026-11-02&to=2026-11-02')->assertOk()->json('data.totals'))->keyBy('rule_code');

    expect($worked)->toBeGreaterThan(600)
        ->and($totals['D1']['minutes'])->toBe(120)
        ->and($totals['D1']['wage_type_code'])->toBe('1500')
        ->and($totals['D2']['minutes'])->toBe($worked - 600)
        ->and($totals['D2']['premium_percent'])->toBe('50.00');

    timeAs($this->worker, $this->tenant)->getJson("/api/v1/hr/time/overtime?from=2026-11-02&to=2026-11-02&user_id={$this->hr->id}")->assertForbidden();
    timeAs($this->worker, $this->tenant)->postJson('/api/v1/hr/overtime-rules', ['code' => 'X', 'name' => 'x', 'basis' => 'daily', 'threshold_minutes' => 1, 'premium_percent' => '1'])->assertForbidden();
});
