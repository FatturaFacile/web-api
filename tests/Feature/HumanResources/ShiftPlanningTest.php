<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Mail\ShiftPlanPublishedMail;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\LeaveType;
use Modules\HumanResources\Models\Shift;
use Modules\HumanResources\Models\ShiftType;
use Modules\HumanResources\Models\TimeClockEvent;

uses(RefreshDatabase::class);

function shiftMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt('secret123'), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function shiftApi(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

function shiftEmployee(Tenant $tenant, ?User $user = null, ?string $departmentId = null): Employee
{
    return Employee::factory()->create(['tenant_id' => $tenant->id, 'user_id' => $user?->id, 'department_id' => $departmentId, 'hire_date' => '2020-01-01', 'termination_date' => null]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function shiftType(Tenant $tenant, string $code, string $category, ?string $start, ?string $end, int $break = 0, array $attributes = []): ShiftType
{
    $id = shiftApi(test()->manager, $tenant)->postJson('/api/v1/hr/shift-types', [
        'code' => $code, 'name' => $code, 'category' => $category, 'start_time' => $start, 'end_time' => $end, 'break_minutes' => $break, ...$attributes,
    ])->assertCreated()->json('data.id');

    return ShiftType::query()->withoutGlobalScopes()->findOrFail($id);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    Mail::fake();
    $this->tenant = Tenant::create(['name' => 'Turni SA', 'legal_name' => 'Turni SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altri SA', 'legal_name' => 'Altri SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = shiftMember($this->tenant, 'admin');
    $this->otherManager = shiftMember($this->other, 'admin');
    $this->worker = shiftMember($this->tenant);
    $this->department = Department::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->employee = shiftEmployee($this->tenant, $this->worker, $this->department->id);
});

test('a rotation runs through its steps from the start and honours the offset', function (): void {
    $early = shiftType($this->tenant, 'E', 'working', '06:00', '14:00', 30);
    $late = shiftType($this->tenant, 'L', 'working', '14:00', '22:00', 30);
    $api = shiftApi($this->manager, $this->tenant);
    $sequence = $api->postJson('/api/v1/hr/shift-sequences', ['name' => 'Rotazione', 'pattern' => 'rotation', 'steps' => [$early->id, $late->id, null]])->assertCreated()->json('data.id');
    $api->postJson('/api/v1/hr/shift-sequences/assignments', ['employee_id' => $this->employee->id, 'shift_sequence_id' => $sequence, 'start_date' => '2026-11-02', 'offset_days' => 1])->assertCreated();

    $api->postJson('/api/v1/hr/shifts/generate', ['from' => '2026-11-02', 'to' => '2026-11-05'])->assertOk()->assertJsonPath('data.created', 3);

    $byDay = Shift::query()->withoutGlobalScopes()->orderBy('work_date')->get()->mapWithKeys(fn (Shift $shift): array => [$shift->work_date->format('Y-m-d') => $shift->shift_type_id]);
    expect($byDay->all())->toBe(['2026-11-02' => $late->id, '2026-11-04' => $early->id, '2026-11-05' => $late->id]);
});

test('weekly patterns leave Sundays or the weekend free', function (string $pattern, int $expected): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $api = shiftApi($this->manager, $this->tenant);
    $sequence = $api->postJson('/api/v1/hr/shift-sequences', ['name' => $pattern, 'pattern' => $pattern, 'steps' => [$day->id]])->assertCreated()->json('data.id');
    $api->postJson('/api/v1/hr/shift-sequences/assignments', ['employee_id' => $this->employee->id, 'shift_sequence_id' => $sequence, 'start_date' => '2026-11-02'])->assertCreated();

    $api->postJson('/api/v1/hr/shifts/generate', ['from' => '2026-11-02', 'to' => '2026-11-08'])->assertOk()->assertJsonPath('data.created', $expected);
})->with([['weekly_without_sunday', 6], ['weekly_without_weekend', 5], ['manual', 0]]);

test('exceptions apply by employee before department before company, and a generation keeps manual shifts and leave', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $night = shiftType($this->tenant, 'N', 'working', '20:00', '23:00');
    $colleague = shiftEmployee($this->tenant, null, $this->department->id);
    $api = shiftApi($this->manager, $this->tenant);
    $sequence = $api->postJson('/api/v1/hr/shift-sequences', ['name' => 'Giorno', 'pattern' => 'weekly_without_weekend', 'steps' => [$day->id]])->json('data.id');

    foreach ([$this->employee, $colleague] as $employee) {
        $api->postJson('/api/v1/hr/shift-sequences/assignments', ['employee_id' => $employee->id, 'shift_sequence_id' => $sequence, 'start_date' => '2026-11-02'])->assertCreated();
    }

    $api->postJson('/api/v1/hr/shift-exceptions', ['exception_date' => '2026-11-02', 'department_id' => $this->department->id, 'reason' => 'Chiusura reparto'])->assertCreated();
    $api->postJson('/api/v1/hr/shift-exceptions', ['exception_date' => '2026-11-02', 'employee_id' => $this->employee->id, 'shift_type_id' => $night->id])->assertCreated();

    $leaveType = LeaveType::factory()->create(['tenant_id' => $this->tenant->id]);
    LeaveRequest::factory()->create(['tenant_id' => $this->tenant->id, 'employee_id' => $colleague->id, 'leave_type_id' => $leaveType->id, 'start_date' => '2026-11-03', 'end_date' => '2026-11-03', 'status' => 'approved']);
    $manual = $api->postJson('/api/v1/hr/shifts', ['employee_id' => $colleague->id, 'shift_type_id' => $night->id, 'work_date' => '2026-11-04'])->assertCreated()->json('data.id');

    $api->postJson('/api/v1/hr/shifts/generate', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertOk();
    $api->postJson('/api/v1/hr/shifts/generate', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertOk();

    $shifts = Shift::query()->withoutGlobalScopes()->whereDate('work_date', '<=', '2026-11-03')->get();
    expect($shifts->where('employee_id', $this->employee->id)->firstWhere(fn (Shift $shift) => $shift->work_date->format('Y-m-d') === '2026-11-02')->shift_type_id)->toBe($night->id)
        ->and($shifts->where('employee_id', $colleague->id)->count())->toBe(0)
        ->and(Shift::query()->withoutGlobalScopes()->find($manual))->not->toBeNull();
});

test('a night shift across the clock change counts real minutes in both directions', function (string $date, int $minutes): void {
    $night = shiftType($this->tenant, 'N', 'working', '22:00', '06:00');
    $id = shiftApi($this->manager, $this->tenant)->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $night->id, 'work_date' => $date])->assertCreated()->json('data.id');

    $shift = Shift::query()->withoutGlobalScopes()->findOrFail($id);
    expect($shift->planned_minutes)->toBe($minutes);
})->with([['2026-10-24', 540], ['2027-03-27', 420], ['2026-11-07', 480]]);

test('shift instants are stored in UTC and shown in local time', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:00');
    $id = shiftApi($this->manager, $this->tenant)->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $day->id, 'work_date' => '2026-07-01'])->assertCreated()->json('data.id');

    expect(Shift::query()->withoutGlobalScopes()->findOrFail($id)->getRawOriginal('starts_at'))->toStartWith('2026-07-01 06:00:00');

    $board = shiftApi($this->manager, $this->tenant)->getJson("/api/v1/hr/shifts/board?from=2026-07-01&to=2026-07-01&department_id={$this->department->id}")->assertOk();
    expect($board->json('data.rows.0.shifts.0.starts_at'))->toBe('2026-07-01T08:00:00+02:00');
});

test('the plan is checked with the working time engine and blocks publication until acknowledged', function (): void {
    $late = shiftType($this->tenant, 'L', 'working', '14:00', '23:30', 60);
    $early = shiftType($this->tenant, 'E', 'working', '05:00', '12:00', 30);
    $api = shiftApi($this->manager, $this->tenant);
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $late->id, 'work_date' => '2026-11-02'])->assertCreated();
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $early->id, 'work_date' => '2026-11-03'])->assertCreated();

    $response = $api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertUnprocessable();
    expect($response->json('errors.violations.0.message'))->toContain('art. 15a')
        ->and(Shift::query()->withoutGlobalScopes()->where('status', 'published')->count())->toBe(0);
    Mail::assertNothingQueued();

    $publication = $api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-03', 'acknowledgement_reason' => 'Permesso cantonale n. 12'])->assertCreated();
    expect($publication->json('data.shift_count'))->toBe(2)
        ->and($publication->json('data.violations'))->not->toBeEmpty()
        ->and(Shift::query()->withoutGlobalScopes()->where('status', 'published')->count())->toBe(2);
});

test('breaks shorter than the law and weeks over the legal maximum are blocking findings', function (): void {
    $long = shiftType($this->tenant, 'X', 'working', '06:00', '17:30', 15);
    $api = shiftApi($this->manager, $this->tenant);

    foreach (['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'] as $date) {
        $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $long->id, 'work_date' => $date])->assertCreated();
    }

    $violations = collect($api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-08'])->assertUnprocessable()->json('errors.violations'));

    expect($violations->pluck('message')->filter(fn (string $message) => str_contains($message, 'art. 15 LL'))->count())->toBe(5)
        ->and($violations->pluck('message')->filter(fn (string $message) => str_contains($message, 'art. 9 LL'))->count())->toBe(1);
});

test('publication mails each employee only the own shifts', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $colleague = shiftEmployee($this->tenant);
    $api = shiftApi($this->manager, $this->tenant);
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-02'])->assertCreated();
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $colleague->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-02'])->assertCreated();

    $api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-02'])->assertCreated();

    Mail::assertQueued(ShiftPlanPublishedMail::class, 2);
    Mail::assertQueued(ShiftPlanPublishedMail::class, fn (ShiftPlanPublishedMail $mail): bool => $mail->hasTo($this->employee->work_email) && count($mail->lines) === 1 && $mail->employeeName === $this->employee->full_name);
});

test('published shifts feed planned time and absences, drafts do not, and recorded minutes stay untouched', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $training = shiftType($this->tenant, 'FORM', 'absence', null, null, 0, ['is_paid_absence' => true]);
    $api = shiftApi($this->manager, $this->tenant);
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-02'])->assertCreated();
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $training->id, 'work_date' => '2026-11-03'])->assertCreated();

    foreach ([['2026-11-02 07:03:00', 'In'], ['2026-11-02 15:41:00', 'Out']] as [$at, $type]) {
        TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->worker->id, 'occurred_at' => $at, 'event_type' => $type]);
    }

    $draft = shiftApi($this->worker, $this->tenant)->getJson("/api/v1/hr/time/report?employee_id={$this->worker->id}&from=2026-11-02&to=2026-11-03")->assertOk();
    expect($draft->json('data.days.0'))->not->toHaveKey('planned_minutes');

    $api = shiftApi($this->manager, $this->tenant);
    $api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-03'])->assertCreated();

    $report = shiftApi($this->worker, $this->tenant)->getJson("/api/v1/hr/time/report?employee_id={$this->worker->id}&from=2026-11-02&to=2026-11-03")->assertOk();
    expect($report->json('data.days.0.planned_minutes'))->toBe(480)
        ->and($report->json('data.days.0.worked_minutes'))->toBe(518)
        ->and($report->json('data.days.1.absence'))->toBe('FORM')
        ->and($report->json('data.days.1.credited_minutes'))->toBe($report->json('data.days.1.target_minutes'));

    $plan = shiftApi($this->manager, $this->tenant)->getJson('/api/v1/hr/absence-plan?from=2026-11-02&to=2026-11-03')->assertOk();
    expect($plan->json('data.0.source'))->toBe('shift')->and($plan->json('data.0.type'))->toBe('FORM');
});

test('the board reports staffing gaps by weekday, weekend and holiday', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $api = shiftApi($this->manager, $this->tenant);

    foreach ([['weekday', 2], ['weekend', 1], ['holiday', 3]] as [$kind, $count]) {
        $api->postJson('/api/v1/hr/staffing-needs', ['department_id' => $this->department->id, 'shift_type_id' => $day->id, 'day_kind' => $kind, 'required_count' => $count, 'valid_from' => '2026-01-01'])->assertCreated();
    }

    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $day->id, 'work_date' => '2026-12-24'])->assertCreated();

    $coverage = collect($api->getJson("/api/v1/hr/shifts/board?from=2026-12-24&to=2026-12-26&department_id={$this->department->id}&canton=TI")->assertOk()->json('data.coverage'))->keyBy('date');

    expect($coverage['2026-12-24'])->toMatchArray(['required' => 2, 'assigned' => 1, 'gap' => 1])
        ->and($coverage['2026-12-25'])->toMatchArray(['required' => 3, 'assigned' => 0, 'gap' => 3])
        ->and($coverage['2026-12-26'])->toMatchArray(['required' => 3]);
});

test('an employee sees only the own published shifts and reaches no planning route', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $colleague = shiftEmployee($this->tenant);
    $api = shiftApi($this->manager, $this->tenant);
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-02'])->assertCreated();
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $colleague->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-02'])->assertCreated();
    $api->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-02', 'to' => '2026-11-02'])->assertCreated();
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $this->employee->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-03'])->assertCreated();

    $worker = shiftApi($this->worker, $this->tenant);
    $mine = $worker->getJson('/api/v1/hr/time/my-shifts?from=2026-11-01&to=2026-11-30')->assertOk();
    expect($mine->json('data'))->toHaveCount(1)->and($mine->json('data.0.work_date'))->toBe('2026-11-02');

    $worker->getJson('/api/v1/hr/shifts/board?from=2026-11-01&to=2026-11-30')->assertForbidden();
    $worker->postJson('/api/v1/hr/shifts/publish', ['from' => '2026-11-01', 'to' => '2026-11-30'])->assertForbidden();
    $worker->getJson('/api/v1/hr/absence-plan?from=2026-11-01&to=2026-11-30')->assertForbidden();
});

test('tenants are isolated: foreign records are rejected and generic types are read-only', function (): void {
    $day = shiftType($this->tenant, 'D', 'working', '08:00', '16:30', 30);
    $api = shiftApi($this->otherManager, $this->other);

    $foreign = shiftEmployee($this->other);
    $api->postJson('/api/v1/hr/shifts', ['employee_id' => $foreign->id, 'shift_type_id' => $day->id, 'work_date' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('shift_type_id');
    $api->postJson('/api/v1/hr/shift-sequences', ['name' => 'X', 'pattern' => 'rotation', 'steps' => [$day->id]])->assertUnprocessable();
    $api->putJson("/api/v1/hr/shift-types/{$day->id}", ['code' => 'Z', 'name' => 'Z', 'category' => 'absence'])->assertNotFound();

    $genericId = (string) Str::uuid7();
    DB::table('hr_shift_types')->insert(['id' => $genericId, 'tenant_id' => null, 'code' => 'GEN', 'name' => 'Generico', 'category' => 'absence']);

    $listed = shiftApi($this->otherManager, $this->other)->getJson('/api/v1/hr/shift-types')->assertOk();
    expect(collect($listed->json('data'))->pluck('code')->all())->toBe(['GEN']);
    shiftApi($this->otherManager, $this->other)->putJson("/api/v1/hr/shift-types/{$genericId}", ['code' => 'Z', 'name' => 'Z', 'category' => 'absence'])->assertForbidden();
});
