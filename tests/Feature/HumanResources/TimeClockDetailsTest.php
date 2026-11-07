<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\TimeAuditEntry;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeScheduleGroupRule;
use Modules\HumanResources\Services\TimeTracking\PunchRoundingMode;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

uses(RefreshDatabase::class);

function clockMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function clockApi(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

function clockEvent(Tenant $tenant, User $user, string $occurredAt, string $type): TimeClockEvent
{
    return TimeClockEvent::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'occurred_at' => $occurredAt, 'event_type' => $type]);
}

/**
 * @param  array<string, mixed>  $rule
 */
function clockGroup(Tenant $tenant, User $manager, User $member, array $rule, string $validFrom = '2026-01-01'): string
{
    $api = clockApi($manager, $tenant);
    $group = $api->postJson('/api/v1/hr/schedule-groups', ['name' => 'Gruppo '.Str::random(4)])->assertCreated()->json('data.id');
    $api->postJson("/api/v1/hr/schedule-groups/{$group}/rules", ['valid_from' => $validFrom, ...$rule])->assertCreated();
    $api->postJson("/api/v1/hr/schedule-groups/{$group}/members", ['employee_id' => $member->id, 'valid_from' => '2026-01-01'])->assertCreated();

    return $group;
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Clock SA', 'legal_name' => 'Clock SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = clockMember($this->tenant, 'admin');
    $this->worker = clockMember($this->tenant);
    $this->stranger = clockMember($this->other);
});

test('rounding is a derived view: recorded minutes and clock events never change', function (): void {
    clockGroup($this->tenant, $this->manager, $this->worker, ['rounding_minutes' => 15, 'rounding_mode' => 'employer_favour']);
    $in = clockEvent($this->tenant, $this->worker, '2026-11-02 07:03:00', 'In');
    $out = clockEvent($this->tenant, $this->worker, '2026-11-02 15:41:00', 'Out');
    $before = [$in->getRawOriginal('occurred_at'), $out->getRawOriginal('occurred_at')];
    $eventsBefore = DB::table('time_clock_events')->orderBy('id')->get()->toJson();

    $day = clockApi($this->worker, $this->tenant)->getJson('/api/v1/hr/time/payroll-view?from=2026-11-02&to=2026-11-02')->assertOk()->json('data.days.0');

    expect($day['recorded_minutes'])->toBe(518)
        ->and($day['payroll_minutes'])->toBe(495)
        ->and($day['adjustment_minutes'])->toBe(-23)
        ->and(DB::table('time_clock_events')->orderBy('id')->get()->toJson())->toBe($eventsBefore)
        ->and([$in->refresh()->getRawOriginal('occurred_at'), $out->refresh()->getRawOriginal('occurred_at')])->toBe($before);

    $report = clockApi($this->worker, $this->tenant)->getJson("/api/v1/hr/time/report?employee_id={$this->worker->id}&from=2026-11-02&to=2026-11-02")->assertOk();
    expect($report->json('data.days.0.worked_minutes'))->toBe(518);
});

test('rounding modes round each punch on the local grid', function (PunchRoundingMode $mode, bool $isStart, string $expected): void {
    $punch = SwissWorkingTimeRules::parseLocal('2026-11-02 08:07:00');

    expect($mode->round($punch, 15, $isStart)->format('H:i'))->toBe($expected);
})->with([
    [PunchRoundingMode::None, true, '08:07'],
    [PunchRoundingMode::Nearest, true, '08:00'],
    [PunchRoundingMode::EmployeeFavour, true, '08:00'],
    [PunchRoundingMode::EmployeeFavour, false, '08:15'],
    [PunchRoundingMode::EmployerFavour, true, '08:15'],
    [PunchRoundingMode::EmployerFavour, false, '08:00'],
]);

test('rule versions keep history and a past day reads the rule then in force', function (): void {
    $group = clockGroup($this->tenant, $this->manager, $this->worker, ['rounding_minutes' => 5, 'rounding_mode' => 'nearest']);
    clockApi($this->manager, $this->tenant)->postJson("/api/v1/hr/schedule-groups/{$group}/rules", ['valid_from' => '2026-11-03', 'rounding_minutes' => 30, 'rounding_mode' => 'nearest'])->assertCreated();

    foreach ([['2026-11-02', 2], ['2026-11-03', 3]] as [$date]) {
        clockEvent($this->tenant, $this->worker, "{$date} 07:07:00", 'In');
        clockEvent($this->tenant, $this->worker, "{$date} 15:07:00", 'Out');
    }

    $days = clockApi($this->worker, $this->tenant)->getJson('/api/v1/hr/time/payroll-view?from=2026-11-02&to=2026-11-03')->assertOk()->json('data.days');
    expect($days[0]['payroll_minutes'])->toBe(480)->and($days[1]['payroll_minutes'])->toBe(480)
        ->and($days[0]['rule_id'])->not->toBe($days[1]['rule_id']);

    expect(fn () => TimeScheduleGroupRule::query()->withoutGlobalScopes()->first()->update(['rounding_minutes' => 1]))->toThrow(ValidationException::class);
    expect(clockApi($this->manager, $this->tenant)->getJson('/api/v1/hr/schedule-groups')->assertOk()->json('data.0.rules'))->toHaveCount(2);
});

test('a punch within the tolerance counts at the published shift and one outside keeps its time', function (): void {
    clockGroup($this->tenant, $this->manager, $this->worker, ['tolerance_before_minutes' => 5, 'tolerance_after_minutes' => 5]);
    $employee = Employee::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id]);
    $api = clockApi($this->manager, $this->tenant);
    $type = $api->postJson('/api/v1/hr/shift-types', ['code' => 'D', 'name' => 'Giorno', 'category' => 'working', 'start_time' => '08:00', 'end_time' => '12:00'])->json('data.id');

    foreach (['2026-11-02', '2026-11-03'] as $date) {
        $api->postJson('/api/v1/hr/shifts', ['employee_id' => $employee->id, 'shift_type_id' => $type, 'work_date' => $date])->assertCreated();
    }

    $api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertCreated();
    clockEvent($this->tenant, $this->worker, '2026-11-02 08:03:00', 'In');
    clockEvent($this->tenant, $this->worker, '2026-11-02 12:02:00', 'Out');
    clockEvent($this->tenant, $this->worker, '2026-11-03 08:20:00', 'In');
    clockEvent($this->tenant, $this->worker, '2026-11-03 12:00:00', 'Out');

    $days = clockApi($this->worker, $this->tenant)->getJson('/api/v1/hr/time/payroll-view?from=2026-11-02&to=2026-11-03')->assertOk()->json('data.days');
    expect($days[0]['recorded_minutes'])->toBe(239)->and($days[0]['payroll_minutes'])->toBe(240)
        ->and($days[1]['recorded_minutes'])->toBe(220)->and($days[1]['payroll_minutes'])->toBe(220);
});

test('night, Sunday, holiday and daily allowances come from the group and are absent without one', function (): void {
    clockEvent($this->tenant, $this->worker, '2026-11-02 21:00:00', 'In');
    clockEvent($this->tenant, $this->worker, '2026-11-03 02:00:00', 'Out');
    $without = clockApi($this->worker, $this->tenant)->getJson('/api/v1/hr/time/payroll-view?from=2026-11-02&to=2026-11-02')->assertOk()->json('data.days.0');
    expect($without['night_minutes'])->toBe(180)->and($without['night_allowance_minutes'])->toBe(0);

    clockGroup($this->tenant, $this->manager, $this->worker, ['night_allowance_percent' => 10, 'sunday_allowance_percent' => 50, 'holiday_allowance_percent' => 100, 'daily_allowance_amount' => '12.50', 'daily_allowance_minimum_minutes' => 240]);
    clockEvent($this->tenant, $this->worker, '2026-11-08 07:00:00', 'In');
    clockEvent($this->tenant, $this->worker, '2026-11-08 09:00:00', 'Out');
    clockEvent($this->tenant, $this->worker, '2026-12-25 07:00:00', 'In');
    clockEvent($this->tenant, $this->worker, '2026-12-25 09:00:00', 'Out');

    $this->travelTo('2026-12-28 10:00:00');
    $api = clockApi($this->worker, $this->tenant);
    $night = $api->getJson('/api/v1/hr/time/payroll-view?from=2026-11-02&to=2026-11-02')->assertOk()->json('data');
    expect($night['days'][0]['night_allowance_minutes'])->toBe(18)->and($night['days'][0]['daily_allowance_amount'])->toBe('12.50')->and($night['totals']['daily_allowance_amount'])->toBe('12.50');
    expect($api->getJson('/api/v1/hr/time/payroll-view?from=2026-11-08&to=2026-11-08')->json('data.days.0'))->toMatchArray(['sunday_allowance_minutes' => 60, 'daily_allowance_amount' => '0']);
    expect($api->getJson('/api/v1/hr/time/payroll-view?from=2026-12-25&to=2026-12-25')->json('data.days.0.holiday_allowance_minutes'))->toBe(120);
});

test('presence shows who is in now and only to managers', function (): void {
    $night = clockMember($this->tenant);
    $gone = clockMember($this->tenant);
    clockEvent($this->tenant, $this->worker, '2026-11-06 07:00:00', 'In');
    clockEvent($this->tenant, $this->worker, '2026-11-06 09:30:00', 'BreakStart');
    clockEvent($this->tenant, $night, '2026-11-05 21:00:00', 'In');
    clockEvent($this->tenant, $gone, '2026-11-06 06:00:00', 'In');
    clockEvent($this->tenant, $gone, '2026-11-06 09:00:00', 'Out');
    clockEvent($this->other, $this->stranger, '2026-11-06 07:00:00', 'In');

    $present = collect(clockApi($this->manager, $this->tenant)->getJson('/api/v1/hr/time/presence')->assertOk()->json('data'))->keyBy('employee_id');

    expect($present)->toHaveCount(2)
        ->and($present[$this->worker->id]['state'])->toBe('on_break')
        ->and($present[$night->id]['state'])->toBe('working');

    clockApi($this->worker, $this->tenant)->getJson('/api/v1/hr/time/presence')->assertForbidden();
});

test('a reader import is repeatable, audited, keeps real seconds and rejects what it cannot place', function (): void {
    $api = clockApi($this->manager, $this->tenant);
    $api->postJson('/api/v1/hr/time-badges', ['employee_id' => $this->worker->id, 'badge_code' => 'ABC123'])->assertCreated();
    $payload = ['reader_id' => 'portineria-1', 'source' => 'badge', 'punches' => [
        ['external_id' => '1', 'badge_code' => 'ABC123', 'occurred_at' => '2026-11-05 08:01:37', 'punch_type' => 'In'],
        ['external_id' => '2', 'badge_code' => 'ABC123', 'occurred_at' => '2026-11-05 12:00:00', 'punch_type' => 'Meal', 'meal_type' => 'lunch'],
        ['external_id' => '3', 'badge_code' => 'SCONOSCIUTO', 'occurred_at' => '2026-11-05 08:00:00', 'punch_type' => 'In'],
        ['external_id' => '4', 'badge_code' => 'ABC123', 'occurred_at' => '2026-12-05 08:00:00', 'punch_type' => 'Out'],
    ]];

    $first = $api->postJson('/api/v1/hr/time/punches/import', $payload)->assertOk()->json('data');
    expect($first['accepted'])->toBe(2)->and(array_column($first['rejected'], 'external_id'))->toBe(['3', '4']);

    $second = $api->postJson('/api/v1/hr/time/punches/import', $payload)->assertOk()->json('data');
    expect($second['accepted'])->toBe(0)->and($second['duplicates'])->toBe(2);

    $event = TimeClockEvent::query()->withoutGlobalScopes()->sole();
    expect($event->getRawOriginal('occurred_at'))->toStartWith('2026-11-05 07:01:37')->and($event->source->value)->toBe('badge')
        ->and(TimeAuditEntry::query()->withoutGlobalScopes()->where('entity_id', $event->id)->value('reason'))->toContain('portineria-1');
});

test('a badge of another company does not resolve, and only managers import', function (): void {
    clockApi($this->manager, $this->tenant)->postJson('/api/v1/hr/time-badges', ['employee_id' => $this->stranger->id, 'badge_code' => 'X'])->assertNotFound();

    $otherManager = clockMember($this->other, 'admin');
    clockApi($this->manager, $this->tenant)->postJson('/api/v1/hr/time-badges', ['employee_id' => $this->worker->id, 'badge_code' => 'ABC'])->assertCreated();

    $outcome = clockApi($otherManager, $this->other)->postJson('/api/v1/hr/time/punches/import', ['reader_id' => 'r', 'punches' => [
        ['external_id' => '1', 'badge_code' => 'ABC', 'occurred_at' => '2026-11-05 08:00:00', 'punch_type' => 'In'],
        ['external_id' => '2', 'employee_id' => $this->worker->id, 'occurred_at' => '2026-11-05 08:00:00', 'punch_type' => 'In'],
    ]])->assertOk()->json('data');
    expect($outcome['accepted'])->toBe(0)->and($outcome['rejected'])->toHaveCount(2);

    clockApi($this->worker, $this->tenant)->postJson('/api/v1/hr/time/punches/import', ['reader_id' => 'r', 'punches' => [['external_id' => '1', 'badge_code' => 'ABC', 'occurred_at' => '2026-11-05 08:00:00', 'punch_type' => 'In']]])->assertForbidden();
});

test('meals are booked once a day per type, are no time events and stay private', function (): void {
    $api = clockApi($this->worker, $this->tenant);
    $id = $api->postJson('/api/v1/hr/time/meal-bookings', ['meal_type' => 'lunch'])->assertCreated()->json('data.id');
    expect($api->postJson('/api/v1/hr/time/meal-bookings', ['meal_type' => 'lunch'])->assertCreated()->json('data.id'))->toBe($id);
    $api->postJson('/api/v1/hr/time/meal-bookings', ['meal_type' => 'dinner'])->assertCreated();

    expect(TimeClockEvent::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($api->getJson('/api/v1/hr/time/meal-bookings?from=2026-11-06&to=2026-11-06')->assertOk()->json('data'))->toHaveCount(2);

    $colleague = clockMember($this->tenant);
    clockApi($colleague, $this->tenant)->getJson("/api/v1/hr/time/meal-bookings?employee_id={$this->worker->id}&from=2026-11-06&to=2026-11-06")->assertForbidden();
    clockApi($colleague, $this->tenant)->postJson('/api/v1/hr/time/meal-bookings', ['meal_type' => 'lunch', 'employee_id' => $this->worker->id])->assertForbidden();
    clockApi($this->manager, $this->tenant)->postJson('/api/v1/hr/time/meal-bookings', ['meal_type' => 'lunch', 'employee_id' => $this->stranger->id])->assertNotFound();
});
