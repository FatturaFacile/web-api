<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Events\RosterPublishedEvent;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Notifications\ShiftPlanningNotification;

require_once __DIR__.'/ShiftPlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    spDeferForeignKeys();
});

it('generates a 5x2 rotation for a team over a week and rotates the templates between employees', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $first = spEmployee($tenant, 'First Person');
    $second = spEmployee($tenant, 'Second Person');
    $morning = spTemplate($tenant, 'MORN', '06:00', '14:00');
    $afternoon = spTemplate($tenant, 'AFT', '14:00', '22:00');
    $night = spTemplate($tenant, 'NIGHT', '22:00', '06:00');

    $response = $this->postJson('/api/v1/shift-planning/periods/generate', [
        'period_type' => 'weekly', 'start_date' => spMonday()->format('Y-m-d'), 'pattern' => '5x2',
        'employee_ids' => [$first, $second], 'shift_template_ids' => [$morning->id, $afternoon->id, $night->id],
        'open_slots' => [$morning->id => 1],
    ])->assertCreated();

    $period = RosterSchedulePeriod::query()->findOrFail($response->json('data.period.id'));
    $byEmployee = $period->assignments()->whereNotNull('employee_id')->get()->groupBy('employee_id');

    expect($response->json('data'))->toMatchArray(['created' => 17, 'open' => 7, 'blocking' => 0])
        ->and($period->status)->toBe(RosterStatus::Draft)
        ->and($period->jurisdiction->value)->toBe('CH')
        ->and($byEmployee->get($first)->count())->toBe(5)
        ->and($byEmployee->get($first)->pluck('shift_template_id')->unique()->all())->toBe([$morning->id])
        ->and($byEmployee->get($second)->pluck('shift_template_id')->unique()->all())->toBe([$afternoon->id])
        ->and($byEmployee->get($first)->contains(fn ($a): bool => $a->shift_date->isoWeekday() > 5))->toBeFalse();
});

it('generates a 6x6 rotation with the two halves of the team six days apart', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $first = spEmployee($tenant, 'First Person');
    $second = spEmployee($tenant, 'Second Person');
    $morning = spTemplate($tenant, 'MORN', '06:00', '14:00');

    $response = $this->postJson('/api/v1/shift-planning/periods/generate', [
        'period_type' => 'monthly', 'start_date' => spMonday()->startOfMonth()->addMonth()->format('Y-m-d'), 'pattern' => '6x6',
        'employee_ids' => [$first, $second], 'shift_template_ids' => [$morning->id],
    ])->assertCreated();

    $period = RosterSchedulePeriod::query()->findOrFail($response->json('data.period.id'));
    $firstDays = $period->assignments()->where('employee_id', $first)->orderBy('shift_date')->get()->map(fn ($a): int => (int) $period->start_date->diffInDays($a->shift_date));
    $secondDays = $period->assignments()->where('employee_id', $second)->orderBy('shift_date')->get()->map(fn ($a): int => (int) $period->start_date->diffInDays($a->shift_date));

    expect($firstDays->take(6)->all())->toBe([0, 1, 2, 3, 4, 5])
        ->and($secondDays->take(6)->all())->toBe([6, 7, 8, 9, 10, 11])
        ->and($response->json('data.blocking'))->toBe(0);
});

it('clones a previous period one week later and refuses an overlapping period', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $employee = spEmployee($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    $source = spPeriod($tenant);
    spAssign($this, $source, $day, spMonday()->format('Y-m-d'), $employee)->assertCreated();
    spAssign($this, $source, $day, spMonday()->addDays(2)->format('Y-m-d'), null)->assertCreated();

    $clone = $this->postJson('/api/v1/shift-planning/periods/generate', [
        'period_type' => 'weekly', 'start_date' => spMonday()->addWeek()->format('Y-m-d'), 'source_period_id' => $source->id,
    ])->assertCreated();

    $copy = RosterSchedulePeriod::query()->findOrFail($clone->json('data.period.id'));
    $dates = $copy->assignments()->orderBy('shift_date')->pluck('shift_date')->map(fn ($date): string => $date->format('Y-m-d'))->all();

    expect($clone->json('data.created'))->toBe(2)
        ->and($dates)->toBe([spMonday()->addWeek()->format('Y-m-d'), spMonday()->addWeek()->addDays(2)->format('Y-m-d')])
        ->and($copy->assignments()->whereNull('employee_id')->count())->toBe(1);

    $this->postJson('/api/v1/shift-planning/periods/generate', [
        'period_type' => 'weekly', 'start_date' => spMonday()->addWeek()->format('Y-m-d'), 'source_period_id' => $source->id,
    ])->assertStatus(422);
});

it('publishes a roster, notifies the assigned employees and sends the surcharge breakdown to payroll', function (): void {
    Notification::fake();
    Event::fake([RosterPublishedEvent::class]);
    $tenant = spTenant('CH');
    $manager = spUser($tenant, 'owner');
    spActAs($tenant, $manager);
    $user = spUser($tenant);
    $employee = spEmployee($tenant, user: $user);
    $period = spPeriod($tenant);
    $night = spTemplate($tenant, 'NIGHT', '22:00', '06:00');
    spAssign($this, $period, $night, spMonday()->format('Y-m-d'), $employee)->assertCreated();
    spAssign($this, $period, $night, spMonday()->addDay()->format('Y-m-d'), null)->assertCreated();

    $response = $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();

    expect($response->json('data'))->toMatchArray(['assigned' => 1, 'open' => 1])
        ->and($response->json('data.period.status'))->toBe('published')
        ->and($period->refresh()->published_by)->toBe($manager->id);
    Notification::assertSentTo($user, ShiftPlanningNotification::class, fn ($notification): bool => $notification->toArray($user)['translation_key'] === 'SHIFT_PLANNING.NOTIFY_ROSTER_PUBLISHED');
    Event::assertDispatched(RosterPublishedEvent::class, fn (RosterPublishedEvent $event): bool => count($event->shifts) === 1
        && $event->shifts[0]['employee_id'] === $employee
        && $event->shifts[0]['surcharge_breakdown']['night_minutes'] === 420
        && $event->jurisdiction === 'CH');
});

it('refuses to publish a roster whose shifts no longer pass the checks, and publishes a draft only once', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $employee = spEmployee($tenant);
    $period = spPeriod($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employee)->assertCreated();

    // A leave approved after the shift was planned.
    spLeave($tenant, $employee, spMonday()->format('Y-m-d'), spMonday()->format('Y-m-d'));

    $blocked = $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertStatus(422);
    expect(spRuleCodes($blocked))->toBe(['LEAVE.APPROVED_CONFLICT'])
        ->and($blocked->json('invalid_params.0.params'))->toMatchArray(['employee_id' => $employee, 'shift_date' => spMonday()->format('Y-m-d')])
        ->and($period->refresh()->status)->toBe(RosterStatus::Draft);

    $period->assignments()->delete();
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertStatus(422);
});

it('locks only a published roster and a locked roster cannot be changed', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);

    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/lock")->assertStatus(422);
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertOk();
    $locked = $this->postJson("/api/v1/shift-planning/periods/{$period->id}/lock")->assertOk();
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertStatus(422);

    expect($locked->json('data.status'))->toBe('locked')->and($locked->json('data.locked_at'))->not->toBeNull();
});

it('builds the matrix by employee with contractual against scheduled hours, coverage and cost totals', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $anna = spEmployee($tenant, 'Anna Rossi', hourlyCost: 20.0, weeklyHours: 40.0);
    $bruno = spEmployee($tenant, 'Bruno Bianchi', hourlyCost: 30.0, weeklyHours: 20.0);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    $monday = spMonday()->format('Y-m-d');
    spAssign($this, $period, $day, $monday, $anna)->assertCreated();
    spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), $anna)->assertCreated();
    spAssign($this, $period, $day, $monday, $bruno)->assertCreated();
    spAssign($this, $period, $day, $monday, null)->assertCreated();
    spAssign($this, $period, $day, $monday, null)->assertCreated();

    $matrix = $this->getJson("/api/v1/shift-planning/periods/{$period->id}/matrix")->assertOk()->json('data');
    $rows = collect($matrix['rows'])->keyBy('employee_name');
    $coverage = collect($matrix['coverage'])->firstWhere('date', $monday);

    expect($matrix['days'])->toHaveCount(7)
        ->and($rows['Anna Rossi'])->toMatchArray(['contract_minutes' => 2400, 'scheduled_minutes' => 960, 'utilisation_percent' => 40.0, 'labor_cost' => '320.00'])
        ->and($rows['Bruno Bianchi'])->toMatchArray(['contract_minutes' => 1200, 'scheduled_minutes' => 480, 'utilisation_percent' => 40.0, 'labor_cost' => '240.00'])
        ->and($rows['Anna Rossi']['cells'][$monday])->toHaveCount(1)
        ->and($matrix['open_shifts'])->toHaveCount(2)
        ->and($coverage)->toMatchArray(['assigned' => 2, 'open' => 2, 'coverage_ratio' => 0.5, 'level' => 'low'])
        ->and($matrix['totals'])->toMatchArray(['labor_cost' => '560.00', 'scheduled_minutes' => 1440, 'open_shifts' => 2])
        ->and($matrix['totals']['labor_cost_by_day'][$monday])->toBe('400.00');
});

it('builds the matrix by required role and marks a mostly open slot as critical', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $anna = spEmployee($tenant, 'Anna Rossi');
    $forklift = spSkill($tenant);
    spGiveSkill($tenant, $anna, $forklift);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    $monday = spMonday()->format('Y-m-d');
    spAssign($this, $period, $day, $monday, $anna, $forklift)->assertCreated();
    spAssign($this, $period, $day, $monday, null, $forklift)->assertCreated();
    spAssign($this, $period, $day, $monday, null, $forklift)->assertCreated();

    $matrix = $this->getJson("/api/v1/shift-planning/periods/{$period->id}/matrix?group_by=role")->assertOk()->json('data');

    expect($matrix['group_by'])->toBe('role')
        ->and($matrix['rows'])->toHaveCount(1)
        ->and($matrix['rows'][0])->toMatchArray(['role_or_skill_id' => $forklift, 'scheduled_minutes' => 480])
        ->and($matrix['rows'][0]['cells'][$monday])->toHaveCount(3)
        ->and(collect($matrix['coverage'])->firstWhere('date', $monday)['level'])->toBe('critical');

    $this->getJson("/api/v1/shift-planning/periods/{$period->id}/matrix?group_by=nonsense")->assertStatus(422);
});

it('lists the periods of a type that overlap a window, newest first', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $first = spPeriod($tenant);
    $second = spPeriod($tenant, spMonday()->addWeek());
    $other = spTenant('CH');
    spPeriod($other);
    $monday = spMonday()->format('Y-m-d');

    $all = $this->getJson('/api/v1/shift-planning/periods')->assertOk()->json('data');
    $window = $this->getJson("/api/v1/shift-planning/periods?period_type=weekly&from={$monday}&to={$monday}")->assertOk()->json('data');

    expect(collect($all)->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and(collect($window)->pluck('id')->all())->toBe([$first->id])
        ->and($this->getJson('/api/v1/shift-planning/periods?period_type=monthly')->json('data'))->toBe([]);
    $this->getJson('/api/v1/shift-planning/periods?period_type=yearly')->assertStatus(422);
});
