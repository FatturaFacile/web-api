<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Models\TimePublicHoliday;
use Modules\HumanResources\Models\TimeRecordingProfile;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Rossi SA', 'legal_name' => 'Rossi SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->otherTenant = Tenant::create(['name' => 'Bianchi SA', 'legal_name' => 'Bianchi SA', 'country' => 'CH', 'default_currency' => 'CHF']);

    $this->manager = ttMember($this->tenant, 'admin');
    $this->employee = ttMember($this->tenant, 'member');
    $this->colleague = ttMember($this->tenant, 'member');
    $this->stranger = ttMember($this->otherTenant, 'member');
});

function ttMember(Tenant $tenant, string $role): User
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

function ttApi(User $user, ?Tenant $tenant = null)
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

function ttEvent(User $user, string $occurredAt, string $eventType = 'In', ?Tenant $tenant = null): TimeClockEvent
{
    return TimeClockEvent::factory()->create([
        'tenant_id' => ($tenant ?? test()->tenant)->id,
        'user_id' => $user->id,
        'occurred_at' => $occurredAt,
        'event_type' => $eventType,
    ]);
}

function ttReport(User $user, string $from, string $to, ?User $viewer = null): TestResponse
{
    return ttApi($viewer ?? $user)->getJson("/api/v1/hr/time/report?employee_id={$user->id}&from={$from}&to={$to}");
}

test('an employee clocks through the enforced sequence and every event is in the history', function (): void {
    $api = ttApi($this->employee);

    $in = $api->postJson('/api/v1/hr/time/events', ['event_type' => 'In', 'source' => 'badge'])
        ->assertCreated()
        ->assertJsonPath('data.employee_id', $this->employee->id)
        ->assertJsonPath('data.source', 'badge')
        ->assertJsonPath('data.occurred_at', '2026-11-06T11:00:00+01:00');

    $api->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])->assertUnprocessable()->assertJsonValidationErrors('event_type');
    $api->postJson('/api/v1/hr/time/events', ['event_type' => 'BreakEnd'])->assertUnprocessable();
    $api->postJson('/api/v1/hr/time/events', ['event_type' => 'BreakStart'])->assertCreated();
    $api->postJson('/api/v1/hr/time/events', ['event_type' => 'BreakEnd'])->assertCreated()->assertJsonPath('data.source', 'web');
    $api->postJson('/api/v1/hr/time/events', ['event_type' => 'Out'])->assertCreated();
    $api->postJson('/api/v1/hr/time/events', ['event_type' => 'Out'])->assertUnprocessable();

    $entry = TimeAuditEntry::withoutGlobalScopes()->where('entity_id', $in->json('data.id'))->sole();

    expect($entry->action)->toBe('Created')
        ->and($entry->actor_id)->toBe($this->employee->id)
        ->and($entry->after_values['event_type'])->toBe('In')
        ->and(TimeClockEvent::withoutGlobalScopes()->count())->toBe(4);
});

test('the clock timestamp is cut to the minute', function (): void {
    $this->travelTo('2026-11-06 10:00:42');

    ttApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])
        ->assertCreated()
        ->assertJsonPath('data.occurred_at', '2026-11-06T11:00:00+01:00');
});

test('a shift started yesterday can still be closed', function (): void {
    ttEvent($this->employee, '2026-11-05 22:00:00');

    ttApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'Out'])->assertCreated();
});

test('a deleted event does not count for the clock sequence', function (): void {
    ttEvent($this->employee, '2026-11-06 08:00:00')->delete();

    ttApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])->assertCreated();
});

test('the source must be one of the instruments', function (): void {
    ttApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In', 'source' => 'telepathy'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');
});

test('only a manager can enter a record in the past or for somebody else, with a reason', function (): void {
    ttApi($this->employee)->postJson('/api/v1/hr/time/events', [
        'event_type' => 'In', 'occurred_at' => '2026-11-02 08:00:00', 'reason' => 'Backdated',
    ])->assertForbidden();

    ttApi($this->employee)->postJson('/api/v1/hr/time/events', [
        'event_type' => 'In', 'employee_id' => $this->colleague->id, 'reason' => 'For a friend',
    ])->assertForbidden();

    ttApi($this->manager)->postJson('/api/v1/hr/time/events', [
        'event_type' => 'In', 'employee_id' => $this->employee->id, 'occurred_at' => '2026-11-02 08:00:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('reason');

    ttApi($this->manager)->postJson('/api/v1/hr/time/events', [
        'event_type' => 'In', 'employee_id' => $this->employee->id, 'occurred_at' => '2026-11-02 08:00:00', 'reason' => 'Foglio cartaceo', 'source' => 'paper',
    ])->assertCreated()->assertJsonPath('data.source', 'paper');

    expect(TimeAuditEntry::withoutGlobalScopes()->where('reason', 'Foglio cartaceo')->sole()->actor_id)->toBe($this->manager->id);
});

test('a time without an offset is local, a time with an offset is kept as that instant', function (): void {
    $api = ttApi($this->manager);
    $payload = ['event_type' => 'In', 'employee_id' => $this->employee->id, 'reason' => 'Correzione'];

    $winter = $api->postJson('/api/v1/hr/time/events', [...$payload, 'occurred_at' => '2026-07-01 08:00:00'])
        ->assertCreated()
        ->assertJsonPath('data.occurred_at', '2026-07-01T08:00:00+02:00');
    $api->postJson('/api/v1/hr/time/events', [...$payload, 'occurred_at' => '2026-07-01T22:30:00Z'])
        ->assertCreated()
        ->assertJsonPath('data.occurred_at', '2026-07-02T00:30:00+02:00');

    expect(DB::table('time_clock_events')->where('id', $winter->json('data.id'))->value('occurred_at'))->toStartWith('2026-07-01 06:00:00');
});

test('a clock event cannot be in the future', function (): void {
    ttApi($this->manager)->postJson('/api/v1/hr/time/events', [
        'event_type' => 'In', 'employee_id' => $this->employee->id, 'occurred_at' => '2026-11-06 12:00:00', 'reason' => 'Domani',
    ])->assertUnprocessable()->assertJsonValidationErrors('occurred_at');
});

test('everyone reads their own events, only managers those of the others', function (): void {
    ttEvent($this->employee, '2026-11-02 08:00:00');
    ttEvent($this->colleague, '2026-11-02 09:00:00');

    ttApi($this->employee)->getJson('/api/v1/hr/time/events')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $this->employee->id);
    ttApi($this->employee)->getJson("/api/v1/hr/time/events?employee_id={$this->colleague->id}")->assertForbidden();
    ttApi($this->manager)->getJson("/api/v1/hr/time/events?employee_id={$this->colleague->id}")->assertOk()->assertJsonCount(1, 'data');
    ttApi($this->employee)->getJson('/api/v1/hr/time/events?employee_id=not-a-uuid')->assertUnprocessable();
});

test('user_id and tenant_id in the query never select the employee or the company', function (): void {
    ttEvent($this->employee, '2026-11-02 08:00:00');
    ttEvent($this->colleague, '2026-11-02 09:00:00');
    ttEvent($this->stranger, '2026-11-02 10:00:00', tenant: $this->otherTenant);

    // Without the header they declare the identity of the request, which the tenant middleware verifies.
    ttApi($this->employee)->getJson("/api/v1/hr/time/events?user_id={$this->colleague->id}")->assertForbidden();

    // With the identity in the header the query parameters are free, and they are not filters of these routes.
    ttApi($this->employee)->withHeader('X-User-Id', $this->employee->id)
        ->getJson("/api/v1/hr/time/events?user_id={$this->colleague->id}&tenant_id={$this->otherTenant->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_id', $this->employee->id);

});

test('a request declaring its context in the query still selects the employee with employee_id', function (): void {
    ttEvent($this->employee, '2026-11-02 08:00:00');

    ttApi($this->manager)
        ->getJson("/api/v1/hr/time/events?user_id={$this->manager->id}&tenant_id={$this->tenant->id}&employee_id={$this->employee->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.employee_id', $this->employee->id);
});

test('the event filter uses local days', function (): void {
    ttEvent($this->employee, '2026-07-01T22:30:00Z');

    ttApi($this->employee)->getJson('/api/v1/hr/time/events?from=2026-07-01&to=2026-07-01')->assertJsonCount(0, 'data');
    ttApi($this->employee)->getJson('/api/v1/hr/time/events?from=2026-07-02&to=2026-07-02')->assertJsonCount(1, 'data');
});

test('one end of a period is enough to filter events', function (): void {
    ttEvent($this->employee, '2026-11-02 08:00:00');

    ttApi($this->employee)->getJson('/api/v1/hr/time/events?to=2026-11-02')->assertOk()->assertJsonCount(1, 'data');
    ttApi($this->employee)->getJson('/api/v1/hr/time/events?from=2026-11-03')->assertOk()->assertJsonCount(0, 'data');
    ttApi($this->employee)->getJson('/api/v1/hr/time/events?from=2026-11-03&to=2026-11-02')->assertUnprocessable();
});

test('a termination date does not need a hire date', function (): void {
    ttApi($this->manager)->putJson("/api/v1/hr/time/profiles/{$this->employee->id}", [
        'mode' => 'Detailed', 'legal_max_weekly_hours' => 45, 'weekly_hours' => 42, 'work_days_per_week' => 5, 'termination_date' => '2026-12-31',
    ])->assertOk()->assertJsonPath('data.termination_date', '2026-12-31');
});

test('a correction needs a manager and a reason and is written to the history', function (): void {
    $event = ttEvent($this->employee, '2026-11-02 08:00:00');

    ttApi($this->employee)->patchJson("/api/v1/hr/time/events/{$event->id}", ['occurred_at' => '2026-11-02 07:00:00', 'reason' => 'Mio errore'])->assertForbidden();
    ttApi($this->manager)->patchJson("/api/v1/hr/time/events/{$event->id}", ['occurred_at' => '2026-11-02 07:00:00'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');

    ttApi($this->manager)->patchJson("/api/v1/hr/time/events/{$event->id}", ['occurred_at' => '2026-11-02 07:30:00', 'note' => 'Riunione', 'reason' => 'Badge dimenticato'])
        ->assertOk()
        ->assertJsonPath('data.occurred_at', '2026-11-02T07:30:00+01:00')
        ->assertJsonPath('data.source', 'manual');

    $entry = TimeAuditEntry::withoutGlobalScopes()->where('action', 'Modified')->sole();

    expect($entry->before_values['occurred_at'])->toBe('2026-11-02T08:00:00+01:00')
        ->and($entry->before_values['source'])->toBe('terminal')
        ->and($entry->after_values['occurred_at'])->toBe('2026-11-02T07:30:00+01:00')
        ->and($entry->after_values['note'])->toBe('Riunione')
        ->and($entry->reason)->toBe('Badge dimenticato')
        ->and($entry->actor_id)->toBe($this->manager->id)
        ->and($entry->actor_name)->toBe($this->manager->name)
        ->and($entry->user_id)->toBe($this->employee->id);
});

test('a deletion is logical, needs a reason and stays consultable', function (): void {
    $event = ttEvent($this->employee, '2026-11-02 08:00:00');
    ttEvent($this->employee, '2026-11-02 12:00:00', 'Out');

    ttApi($this->employee)->deleteJson("/api/v1/hr/time/events/{$event->id}", ['reason' => 'Mio errore'])->assertForbidden();
    ttApi($this->manager)->deleteJson("/api/v1/hr/time/events/{$event->id}")->assertUnprocessable()->assertJsonValidationErrors('reason');

    ttApi($this->manager)->deleteJson("/api/v1/hr/time/events/{$event->id}", ['reason' => 'Doppia timbratura'])
        ->assertOk()
        ->assertJsonPath('data.deleted_by', $this->manager->id)
        ->assertJsonPath('data.deletion_reason', 'Doppia timbratura');

    $row = DB::table('time_clock_events')->where('id', $event->id)->first();
    $entry = TimeAuditEntry::withoutGlobalScopes()->where('action', 'Deleted')->sole();

    expect($row->deleted_at)->not->toBeNull()
        ->and($row->deletion_reason)->toBe('Doppia timbratura')
        ->and($entry->before_values['event_type'])->toBe('In')
        ->and($entry->reason)->toBe('Doppia timbratura')
        ->and($entry->actor_id)->toBe($this->manager->id);

    ttApi($this->employee)->getJson('/api/v1/hr/time/events')->assertJsonCount(1, 'data');
    ttApi($this->employee)->getJson('/api/v1/hr/time/events?include_deleted=1')->assertJsonCount(2, 'data');
    ttReport($this->employee, '2026-11-02', '2026-11-02')->assertJsonPath('data.days.0.worked_minutes', 0);

    ttApi($this->manager)->deleteJson("/api/v1/hr/time/events/{$event->id}", ['reason' => 'Di nuovo'])->assertNotFound();
    ttApi($this->manager)->patchJson("/api/v1/hr/time/events/{$event->id}", ['note' => 'x', 'reason' => 'Modifica'])->assertUnprocessable();
});

test('routes with a malformed id are not found instead of failing on the database', function (): void {
    ttApi($this->manager)->patchJson('/api/v1/hr/time/events/not-a-uuid', ['reason' => 'Motivo'])->assertNotFound();
    ttApi($this->manager)->deleteJson('/api/v1/hr/time/events/not-a-uuid', ['reason' => 'Motivo'])->assertNotFound();
    ttApi($this->manager)->getJson('/api/v1/hr/time/profiles/not-a-uuid')->assertNotFound();
});

test('the simplified recording stores one record per day, and changing it needs a reason', function (): void {
    $api = ttApi($this->employee);

    $api->putJson('/api/v1/hr/time/daily-records', ['date' => '2026-11-02', 'worked_minutes' => 480])->assertOk();
    $api->putJson('/api/v1/hr/time/daily-records', ['date' => '2026-11-02', 'worked_minutes' => 500])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $api->putJson('/api/v1/hr/time/daily-records', ['date' => '2026-11-02', 'worked_minutes' => 500, 'reason' => 'Dimenticata la riunione'])
        ->assertOk()
        ->assertJsonPath('data.worked_minutes', 500);

    $entry = TimeAuditEntry::withoutGlobalScopes()->where('entity_type', 'TimeDailyRecord')->where('action', 'Modified')->sole();

    expect(TimeDailyRecord::withoutGlobalScopes()->count())->toBe(1)
        ->and($entry->before_values['worked_minutes'])->toBe(480)
        ->and($entry->after_values['worked_minutes'])->toBe(500)
        ->and($entry->reason)->toBe('Dimenticata la riunione');
});

test('daily records follow the desktop validation and access rules', function (): void {
    $api = ttApi($this->employee);

    $api->putJson('/api/v1/hr/time/daily-records', ['date' => '2026-11-07', 'worked_minutes' => 480])->assertUnprocessable()->assertJsonValidationErrors('date');
    $api->putJson('/api/v1/hr/time/daily-records', ['date' => '2026-11-02', 'worked_minutes' => 1441])->assertUnprocessable()->assertJsonValidationErrors('worked_minutes');
    $api->putJson('/api/v1/hr/time/daily-records', [
        'date' => '2026-11-02', 'worked_minutes' => 60, 'start_time' => '2026-11-02 23:00:00', 'end_time' => '2026-11-02 22:00:00',
    ])->assertUnprocessable()->assertJsonValidationErrors('end_time');
    $api->putJson('/api/v1/hr/time/daily-records', ['employee_id' => $this->colleague->id, 'date' => '2026-11-02', 'worked_minutes' => 480])->assertForbidden();

    ttApi($this->manager)->putJson('/api/v1/hr/time/daily-records', ['employee_id' => $this->employee->id, 'date' => '2026-11-02', 'worked_minutes' => 480])->assertOk();
    ttApi($this->manager)->putJson('/api/v1/hr/time/daily-records', ['employee_id' => $this->stranger->id, 'date' => '2026-11-02', 'worked_minutes' => 480])->assertNotFound();
});

test('the day of the date check is the local one', function (): void {
    $this->travelTo('2026-11-06 23:30:00');

    ttApi($this->employee)->putJson('/api/v1/hr/time/daily-records', ['date' => '2026-11-07', 'worked_minutes' => 30])->assertOk();
});

test('the report gives days, weeks, balance, statutory overtime and issues', function (): void {
    TimeRecordingProfile::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'canton' => 'ZH']);

    // A week of 50 hours before the period: 5 hours of statutory overtime of the year.
    foreach (['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30'] as $day) {
        foreach ([['07:00', 'In'], ['12:00', 'Out'], ['13:00', 'In'], ['18:00', 'Out']] as [$time, $type]) {
            ttEvent($this->employee, "{$day} {$time}:00", $type);
        }
    }

    foreach ([['08:00', 'In'], ['12:00', 'Out'], ['12:45', 'In'], ['17:15', 'Out']] as [$time, $type]) {
        ttEvent($this->employee, "2026-11-02 {$time}:00", $type);
    }

    ttReport($this->employee, '2026-11-02', '2026-11-08')
        ->assertOk()
        ->assertJsonPath('data.recording_mode', 'Detailed')
        ->assertJsonPath('data.days.0.worked_minutes', 510)
        ->assertJsonPath('data.days.0.break_minutes', 45)
        ->assertJsonPath('data.days.0.target_minutes', 504)
        ->assertJsonPath('data.days.0.balance_minutes', 6)
        ->assertJsonPath('data.days.0.first_in', '2026-11-02T08:00:00+01:00')
        ->assertJsonPath('data.days.1.balance_minutes', -504)
        ->assertJsonPath('data.weeks.0.week_start', '2026-11-02')
        ->assertJsonPath('data.weeks.0.worked_minutes', 510)
        ->assertJsonPath('data.weeks.0.legal_maximum_minutes', 2700)
        ->assertJsonPath('data.statutory_overtime_minutes', 0)
        ->assertJsonPath('data.statutory_overtime_before_minutes', 300)
        ->assertJsonPath('data.statutory_overtime_year_minutes', 300)
        ->assertJsonPath('data.statutory_overtime_cap_minutes', 10200)
        ->assertJsonPath('data.balance', '-33:30');
});

test('a report without a period, with a period of more than a year or reversed is refused', function (): void {
    $api = ttApi($this->employee);

    $api->getJson('/api/v1/hr/time/report')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $api->getJson('/api/v1/hr/time/report?from=2026-01-02&to=2026-01-01')->assertUnprocessable();
    $api->getJson('/api/v1/hr/time/report?from=2025-01-01&to=2026-01-02')->assertUnprocessable();
    $api->getJson('/api/v1/hr/time/report?from=2026-01-01&to=2026-12-31')->assertOk();
});

test('the report of the others is only for managers', function (): void {
    ttReport($this->colleague, '2026-11-02', '2026-11-02', $this->employee)->assertForbidden();
    ttReport($this->colleague, '2026-11-02', '2026-11-02', $this->manager)->assertOk();
    ttReport($this->colleague, '2026-11-02', '2026-11-02')->assertOk();
});

test('the report uses the daily records of a simplified profile', function (): void {
    TimeRecordingProfile::factory()->simplified()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id]);
    TimeDailyRecord::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'work_date' => '2026-11-02', 'worked_minutes' => 450]);
    ttEvent($this->employee, '2026-11-03 08:00:00');

    ttReport($this->employee, '2026-11-02', '2026-11-03')
        ->assertJsonPath('data.recording_mode', 'Simplified')
        ->assertJsonPath('data.days.0.worked_minutes', 450)
        ->assertJsonPath('data.days.1.worked_minutes', 0)
        ->assertJsonPath('data.issues', []);
});

test('holidays of the canton, half days and approved leave adjust the target', function (): void {
    TimeRecordingProfile::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'canton' => 'TI']);
    TimePublicHoliday::create(['tenant_id' => $this->tenant->id, 'holiday_date' => '2026-12-24', 'name' => 'Vigilia', 'day_fraction' => 0.5]);

    $hrEmployee = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id]);
    $paid = LeaveType::factory()->create(['tenant_id' => $this->tenant->id, 'is_paid' => true, 'category' => 'vacation']);
    $unpaid = LeaveType::factory()->create(['tenant_id' => $this->tenant->id, 'is_paid' => false, 'category' => 'unpaid']);
    $leave = ['tenant_id' => $this->tenant->id, 'employee_id' => $hrEmployee->id];

    LeaveRequest::factory()->create([...$leave, 'leave_type_id' => $paid->id, 'status' => 'approved', 'start_date' => '2026-12-14', 'end_date' => '2026-12-14']);
    LeaveRequest::factory()->create([...$leave, 'leave_type_id' => $unpaid->id, 'status' => 'approved', 'start_date' => '2026-12-15', 'end_date' => '2026-12-15']);
    LeaveRequest::factory()->create([...$leave, 'leave_type_id' => $paid->id, 'status' => 'pending_approval', 'start_date' => '2026-12-16', 'end_date' => '2026-12-16']);

    $this->travelTo('2026-12-31 10:00:00');

    ttReport($this->employee, '2026-12-08', '2026-12-24')
        ->assertJsonPath('data.days.0.absence', 'Immacolata')
        ->assertJsonPath('data.days.0.target_minutes', 0)
        ->assertJsonPath('data.days.6.absence', 'vacation')
        ->assertJsonPath('data.days.6.credited_minutes', 504)
        ->assertJsonPath('data.days.6.balance_minutes', 0)
        ->assertJsonPath('data.days.7.absence', 'unpaid')
        ->assertJsonPath('data.days.7.target_minutes', 0)
        ->assertJsonPath('data.days.8.absence', null)
        ->assertJsonPath('data.days.8.target_minutes', 504)
        ->assertJsonPath('data.days.16.absence', 'Vigilia')
        ->assertJsonPath('data.days.16.target_minutes', 252);
});

test('the holidays endpoint merges the canton with generic and company rows, never those of another company', function (): void {
    DB::table('time_public_holidays')->insert([
        ['id' => (string) Str::uuid7(), 'tenant_id' => null, 'canton' => null, 'holiday_date' => '2026-07-01', 'name' => 'Generico', 'day_fraction' => 1],
        ['id' => (string) Str::uuid7(), 'tenant_id' => null, 'canton' => 'ZH', 'holiday_date' => '2026-07-02', 'name' => 'Sechselauten', 'day_fraction' => 0.5],
        ['id' => (string) Str::uuid7(), 'tenant_id' => null, 'canton' => 'GE', 'holiday_date' => '2026-07-03', 'name' => 'Solo Ginevra', 'day_fraction' => 1],
        ['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'canton' => null, 'holiday_date' => '2026-07-01', 'name' => 'Aziendale', 'day_fraction' => 0.25],
        ['id' => (string) Str::uuid7(), 'tenant_id' => $this->otherTenant->id, 'canton' => null, 'holiday_date' => '2026-07-06', 'name' => 'Altra azienda', 'day_fraction' => 1],
    ]);

    $holidays = collect(ttApi($this->employee)->getJson('/api/v1/hr/time/holidays?canton=zh&year=2026')->assertOk()->json('data'))->keyBy('date');

    expect($holidays['2026-01-02']['name'])->toBe('San Bertoldo')
        ->and($holidays['2026-07-01']['name'])->toBe('Aziendale')
        ->and($holidays['2026-07-01']['day_fraction'])->toBe(0.25)
        ->and($holidays['2026-07-02']['day_fraction'])->toBe(0.5)
        ->and($holidays->has('2026-07-03'))->toBeFalse()
        ->and($holidays->has('2026-07-06'))->toBeFalse();

    ttApi($this->employee)->getJson('/api/v1/hr/time/holidays?canton=XX')->assertUnprocessable()->assertJsonValidationErrors('canton');
    ttApi($this->employee)->getJson('/api/v1/hr/time/holidays?year=2026')->assertOk()->assertJsonFragment(['name' => 'SS. Pietro e Paolo']);
});

test('the history shows employees their own entries and managers all of them, company by company', function (): void {
    $event = ttEvent($this->employee, '2026-11-02 08:00:00');
    $otherEvent = ttEvent($this->colleague, '2026-11-02 08:00:00');

    ttApi($this->manager)->patchJson("/api/v1/hr/time/events/{$event->id}", ['note' => 'a', 'reason' => 'Primo'])->assertOk();
    ttApi($this->manager)->patchJson("/api/v1/hr/time/events/{$otherEvent->id}", ['note' => 'b', 'reason' => 'Secondo'])->assertOk();

    $strangerEvent = ttEvent($this->stranger, '2026-11-02 08:00:00', tenant: $this->otherTenant);
    $strangerManager = ttMember($this->otherTenant, 'owner');
    ttApi($strangerManager, $this->otherTenant)->patchJson("/api/v1/hr/time/events/{$strangerEvent->id}", ['note' => 'c', 'reason' => 'Terzo'])->assertOk();

    ttApi($this->employee)->getJson('/api/v1/hr/time/audit')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Primo');
    ttApi($this->employee)->getJson("/api/v1/hr/time/audit?employee_id={$this->colleague->id}")->assertForbidden();
    ttApi($this->manager)->getJson('/api/v1/hr/time/audit')->assertOk()->assertJsonCount(2, 'data');
    ttApi($this->manager)->getJson("/api/v1/hr/time/audit?employee_id={$this->colleague->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Secondo');
    ttApi($this->manager)->getJson("/api/v1/hr/time/audit?employee_id={$this->stranger->id}")->assertOk()->assertJsonCount(0, 'data');
});

test('profiles are read by their owner and managers and written by managers only', function (): void {
    $url = "/api/v1/hr/time/profiles/{$this->employee->id}";
    $payload = [
        'mode' => 'Simplified', 'agreement_date' => '2026-01-15', 'legal_max_weekly_hours' => 50, 'weekly_hours' => 40.5,
        'work_days_per_week' => 5, 'hire_date' => '2024-02-01', 'termination_date' => null, 'canton' => 'ge',
    ];

    ttApi($this->employee)->getJson($url)->assertOk()
        ->assertJsonPath('data.is_configured', false)
        ->assertJsonPath('data.mode', 'Detailed')
        ->assertJsonPath('data.legal_max_weekly_hours', 45);
    ttApi($this->colleague)->getJson($url)->assertForbidden();
    ttApi($this->employee)->putJson($url, $payload)->assertForbidden();

    ttApi($this->manager)->putJson($url, $payload)->assertOk()
        ->assertJsonPath('data.is_configured', true)
        ->assertJsonPath('data.mode', 'Simplified')
        ->assertJsonPath('data.agreement_date', '2026-01-15')
        ->assertJsonPath('data.weekly_hours', '40.50')
        ->assertJsonPath('data.canton', 'GE');

    ttApi($this->manager)->putJson($url, [...$payload, 'mode' => 'Waived', 'reason' => 'Nuovo accordo'])->assertOk();

    $entries = TimeAuditEntry::withoutGlobalScopes()->where('entity_type', 'TimeRecordingProfile')->orderBy('id')->get();

    expect(TimeRecordingProfile::withoutGlobalScopes()->count())->toBe(1)
        ->and($entries->pluck('action')->all())->toBe(['Created', 'Modified'])
        ->and($entries[1]->before_values['mode'])->toBe('Simplified')
        ->and($entries[1]->after_values['mode'])->toBe('Waived')
        ->and($entries[1]->reason)->toBe('Nuovo accordo');
});

test('profile values are validated', function (): void {
    $url = "/api/v1/hr/time/profiles/{$this->employee->id}";
    $payload = ['mode' => 'Detailed', 'legal_max_weekly_hours' => 45, 'weekly_hours' => 42, 'work_days_per_week' => 5];
    $api = ttApi($this->manager);

    $api->putJson($url, [...$payload, 'mode' => 'Whatever'])->assertUnprocessable()->assertJsonValidationErrors('mode');
    $api->putJson($url, [...$payload, 'legal_max_weekly_hours' => 40])->assertUnprocessable()->assertJsonValidationErrors('legal_max_weekly_hours');
    $api->putJson($url, [...$payload, 'work_days_per_week' => 8])->assertUnprocessable()->assertJsonValidationErrors('work_days_per_week');
    $api->putJson($url, [...$payload, 'canton' => 'XX'])->assertUnprocessable()->assertJsonValidationErrors('canton');
    $api->putJson($url, [...$payload, 'hire_date' => '2026-02-01', 'termination_date' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('termination_date');
});

test('the owner of the tenant has the same rights as an admin', function (): void {
    $owner = ttMember($this->tenant, 'owner');
    $event = ttEvent($this->employee, '2026-11-02 08:00:00');

    ttApi($owner)->getJson("/api/v1/hr/time/events?employee_id={$this->employee->id}")->assertOk();
    ttApi($owner)->patchJson("/api/v1/hr/time/events/{$event->id}", ['note' => 'x', 'reason' => 'Motivo'])->assertOk();
});

test('other roles have no access to the data of others', function (): void {
    $accountant = ttMember($this->tenant, 'accountant');

    ttApi($accountant)->getJson("/api/v1/hr/time/events?employee_id={$this->employee->id}")->assertForbidden();
});

test('being admin of another company gives no rights in this one', function (): void {
    $this->employee->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $event = ttEvent($this->colleague, '2026-11-02 08:00:00');

    ttApi($this->employee)->getJson("/api/v1/hr/time/events?employee_id={$this->colleague->id}")->assertForbidden();
    ttApi($this->employee)->patchJson("/api/v1/hr/time/events/{$event->id}", ['note' => 'x', 'reason' => 'Motivo'])->assertForbidden();
    ttApi($this->employee)->getJson('/api/v1/hr/time/audit?employee_id='.$this->colleague->id)->assertForbidden();
});

test('a manager cannot reach the records of another company', function (): void {
    $event = ttEvent($this->stranger, '2026-11-02 08:00:00', tenant: $this->otherTenant);

    ttApi($this->manager)->patchJson("/api/v1/hr/time/events/{$event->id}", ['note' => 'x', 'reason' => 'Motivo'])->assertNotFound();
    ttApi($this->manager)->deleteJson("/api/v1/hr/time/events/{$event->id}", ['reason' => 'Motivo'])->assertNotFound();
    ttApi($this->manager)->getJson("/api/v1/hr/time/events?employee_id={$this->stranger->id}")->assertOk()->assertJsonCount(0, 'data');
    ttReport($this->stranger, '2026-11-02', '2026-11-02', $this->manager)->assertNotFound();
    ttApi($this->manager)->getJson("/api/v1/hr/time/profiles/{$this->stranger->id}")->assertNotFound();
    ttApi($this->manager)->putJson("/api/v1/hr/time/profiles/{$this->stranger->id}", [
        'mode' => 'Detailed', 'legal_max_weekly_hours' => 45, 'weekly_hours' => 42, 'work_days_per_week' => 5,
    ])->assertNotFound();
    ttApi($this->manager)->postJson('/api/v1/hr/time/events', [
        'event_type' => 'In', 'employee_id' => $this->stranger->id, 'reason' => 'Motivo',
    ])->assertNotFound();
});

test('a user cannot use the tenant header of a company they do not belong to', function (): void {
    ttApi($this->employee, $this->otherTenant)->getJson('/api/v1/hr/time/events')->assertForbidden();
});

test('the records of the same employee stay apart between two companies', function (): void {
    $this->employee->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    ttEvent($this->employee, '2026-11-02 08:00:00');
    ttEvent($this->employee, '2026-11-02 09:00:00', 'Out', $this->otherTenant);

    ttApi($this->employee)->getJson('/api/v1/hr/time/events')->assertJsonCount(1, 'data')->assertJsonPath('data.0.event_type', 'In');
    ttApi($this->employee, $this->otherTenant)->getJson('/api/v1/hr/time/events')->assertJsonCount(1, 'data')->assertJsonPath('data.0.event_type', 'Out');
});

test('the tenant of a new record comes from the context, not from the request', function (): void {
    ttApi($this->employee)->postJson('/api/v1/hr/time/events', ['event_type' => 'In', 'tenant_id' => $this->otherTenant->id])->assertCreated();

    expect(TimeClockEvent::withoutGlobalScopes()->sole()->tenant_id)->toBe($this->tenant->id);
});

test('unauthenticated requests are refused', function (): void {
    $this->getJson('/api/v1/hr/time/events')->assertUnauthorized();
    $this->postJson('/api/v1/hr/time/events', ['event_type' => 'In'])->assertUnauthorized();
});
