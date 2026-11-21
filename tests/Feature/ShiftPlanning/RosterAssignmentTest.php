<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ShiftPlanningTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    spDeferForeignKeys();
});

it('blocks an Italian morning shift right after an afternoon shift with the rule code IT.DAILY_REST_11H', function (): void {
    $tenant = spTenant('IT');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant, country: 'IT');
    $employee = spEmployee($tenant);
    $afternoon = spTemplate($tenant, 'POM', '14:00', '22:00');
    $morning = spTemplate($tenant, 'MAT', '06:00', '14:00');
    $monday = spMonday();

    spAssign($this, $period, $afternoon, $monday->format('Y-m-d'), $employee)->assertCreated();
    $blocked = spAssign($this, $period, $morning, $monday->addDay()->format('Y-m-d'), $employee);

    $blocked->assertStatus(422);
    expect(spRuleCodes($blocked))->toContain('IT.DAILY_REST_11H')
        ->and($blocked->json('invalid_params.0'))->toMatchArray(['severity' => 'blocking_error', 'translation_key' => 'SHIFT_PLANNING.RULE_IT_DAILY_REST_11H'])
        ->and($period->assignments()->count())->toBe(1);
});

it('blocks the seventh consecutive working day in Switzerland and stores the first six', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');

    foreach (range(0, 5) as $offset) {
        spAssign($this, $period, $day, spMonday()->addDays($offset)->format('Y-m-d'), $employee)->assertCreated();
    }

    $seventh = spAssign($this, $period, $day, spMonday()->addDays(6)->format('Y-m-d'), $employee);

    $seventh->assertStatus(422);
    expect(spRuleCodes($seventh))->toContain('CH.MAX_CONSECUTIVE_DAYS')
        ->and($period->assignments()->count())->toBe(6);
});

it('prices a Swiss night shift with the 25% supplement of Art. 17b ArG and returns the surcharge breakdown', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant, hourlyCost: 20.0);
    $night = spTemplate($tenant, 'NIGHT', '22:00', '06:00');

    $response = spAssign($this, $period, $night, spMonday()->format('Y-m-d'), $employee)->assertCreated();

    // 8 h x 20 = 160 plus 7 night hours (23:00-06:00) x 20 x 25% = 35.
    expect($response->json('data.estimated_labor_cost'))->toBe('195.00')
        ->and($response->json('data.assignment.surcharge_breakdown'))->toMatchArray(['night_minutes' => 420, 'night_wage_surcharge_percent' => 25.0, 'tags' => ['night']]);
});

it('blocks a shift that falls into an approved leave and warns on a pending one', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    spLeave($tenant, $employee, spMonday()->format('Y-m-d'), spMonday()->format('Y-m-d'), 'approved');
    spLeave($tenant, $employee, spMonday()->addDay()->format('Y-m-d'), spMonday()->addDay()->format('Y-m-d'), 'pending_approval');

    $approved = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employee);
    $pending = spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), $employee);

    $approved->assertStatus(422);
    $pending->assertCreated();
    expect(spRuleCodes($approved))->toContain('LEAVE.APPROVED_CONFLICT')
        ->and(spRuleCodes($pending))->toContain('LEAVE.PENDING_CONFLICT');
});

it('ignores a rejected leave', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    spLeave($tenant, $employee, spMonday()->format('Y-m-d'), spMonday()->format('Y-m-d'), 'rejected');

    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employee)->assertCreated();
});

it('blocks an employee whose mandatory safety certificate expired before the shift', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $valid = spEmployee($tenant, 'Valid Person');
    $expired = spEmployee($tenant, 'Expired Person');
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    spCertification($tenant, $valid, spMonday()->addYear()->format('Y-m-d'));
    spCertification($tenant, $expired, spMonday()->subDay()->format('Y-m-d'));

    $blocked = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $expired);

    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $valid)->assertCreated();
    $blocked->assertStatus(422);
    expect(spRuleCodes($blocked))->toBe(['SAFETY.CERTIFICATION_INVALID'])
        ->and($blocked->json('invalid_params.0.params'))->toMatchArray(['certification_code' => 'HSE-1']);
});

it('ignores an expired certificate that is not mandatory', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);
    spCertification($tenant, $employee, spMonday()->subYear()->format('Y-m-d'), mandatory: false);

    spAssign($this, $period, spTemplate($tenant, 'DAY', '08:00', '16:00'), spMonday()->format('Y-m-d'), $employee)->assertCreated();
});

it('requires the skill of the shift and accepts an employee who holds it', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $skilled = spEmployee($tenant, 'Skilled Person');
    $unskilled = spEmployee($tenant, 'Unskilled Person');
    $forklift = spSkill($tenant);
    spGiveSkill($tenant, $skilled, $forklift);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');

    $blocked = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $unskilled, $forklift);

    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $skilled, $forklift)->assertCreated();
    expect(spRuleCodes($blocked))->toBe(['SKILL.MISSING']);
});

it('blocks an employee without a contract and warns when the contractual hours are exceeded', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $noContract = spEmployee($tenant, 'No Contract', withContract: false);
    $parttime = spEmployee($tenant, 'Part Timer', weeklyHours: 12.0);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');

    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $noContract)->assertStatus(422);
    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $parttime)->assertCreated();
    $second = spAssign($this, $period, $day, spMonday()->addDay()->format('Y-m-d'), $parttime)->assertCreated();

    expect(spRuleCodes($second))->toContain('CONTRACT.WEEKLY_HOURS_EXCEEDED');
});

it('blocks two overlapping shifts of the same employee', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);

    spAssign($this, $period, spTemplate($tenant, 'DAY', '08:00', '16:00'), spMonday()->format('Y-m-d'), $employee)->assertCreated();
    $overlap = spAssign($this, $period, spTemplate($tenant, 'MID', '12:00', '20:00'), spMonday()->format('Y-m-d'), $employee);

    expect(spRuleCodes($overlap))->toContain('SCHEDULE.OVERLAP');
});

it('moves a shift to another employee, re-checks it and recalculates the cost', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $cheap = spEmployee($tenant, 'Cheap Person', hourlyCost: 20.0);
    $dear = spEmployee($tenant, 'Dear Person', hourlyCost: 40.0);
    $leave = spEmployee($tenant, 'Leave Person');
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    spLeave($tenant, $leave, spMonday()->format('Y-m-d'), spMonday()->format('Y-m-d'));
    $created = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $cheap)->assertCreated();
    $id = $created->json('data.assignment.id');
    $payload = ['shift_date' => spMonday()->format('Y-m-d'), 'shift_template_id' => $day->id];

    $this->patchJson("/api/v1/shift-planning/assignments/{$id}", [...$payload, 'employee_id' => $leave])->assertStatus(422);
    $moved = $this->patchJson("/api/v1/shift-planning/assignments/{$id}", [...$payload, 'employee_id' => $dear])->assertOk();

    expect($created->json('data.estimated_labor_cost'))->toBe('160.00')
        ->and($moved->json('data.estimated_labor_cost'))->toBe('320.00')
        ->and($moved->json('data.assignment.employee_id'))->toBe($dear)
        ->and($period->assignments()->count())->toBe(1);
});

it('keeps an open shift without employee and fills it by an assignment', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');

    $open = spAssign($this, $period, $day, spMonday()->format('Y-m-d'), null)->assertCreated();
    $filled = $this->patchJson('/api/v1/shift-planning/assignments/'.$open->json('data.assignment.id'), ['shift_date' => spMonday()->format('Y-m-d'), 'shift_template_id' => $day->id, 'employee_id' => $employee])->assertOk();

    expect($open->json('data.assignment.is_open'))->toBeTrue()
        ->and($filled->json('data.assignment.is_open'))->toBeFalse();
});

it('refuses a shift outside the period and any change to a locked roster', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'owner'));
    $period = spPeriod($tenant);
    $employee = spEmployee($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');

    spAssign($this, $period, $day, spMonday()->addDays(7)->format('Y-m-d'), $employee)->assertStatus(422);

    $period->update(['status' => 'published']);
    $period->lock((string) auth()->id());

    spAssign($this, $period, $day, spMonday()->format('Y-m-d'), $employee)->assertStatus(422);
});

it('keeps the planner of another tenant out', function (): void {
    $tenant = spTenant('CH');
    $other = spTenant('CH');
    $period = spPeriod($tenant);
    $day = spTemplate($tenant, 'DAY', '08:00', '16:00');
    spActAs($other, spUser($other, 'owner'));

    $this->getJson("/api/v1/shift-planning/periods/{$period->id}/matrix")->assertNotFound();
    $this->postJson('/api/v1/shift-planning/assignments', ['roster_schedule_period_id' => $period->id, 'shift_date' => spMonday()->format('Y-m-d'), 'shift_template_id' => $day->id])->assertStatus(422);
});

it('keeps plain members away from the planner endpoints', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'member'));
    $period = spPeriod($tenant);

    $this->getJson("/api/v1/shift-planning/periods/{$period->id}/matrix")->assertForbidden();
    $this->postJson("/api/v1/shift-planning/periods/{$period->id}/publish")->assertForbidden();
    $this->getJson('/api/v1/shift-planning/my/shifts')->assertStatus(403);
});

it('creates templates with unique codes per tenant and translatable names', function (): void {
    $tenant = spTenant('CH');
    spActAs($tenant, spUser($tenant, 'hr'));
    $body = ['code' => 'MORN', 'name' => ['it' => 'Mattina', 'de' => 'Frühschicht', 'fr' => 'Matin', 'en' => 'Morning'], 'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 30];

    $created = $this->postJson('/api/v1/shift-planning/templates', $body)->assertCreated();
    $this->postJson('/api/v1/shift-planning/templates', $body)->assertStatus(422)->assertJsonValidationErrors('code');
    $this->putJson('/api/v1/shift-planning/templates/'.$created->json('data.id'), [...$body, 'break_minutes' => 45])->assertOk();

    expect($created->json('data.name.de'))->toBe('Frühschicht')
        ->and($created->json('data.start_time'))->toBe('06:00')
        ->and($this->getJson('/api/v1/shift-planning/templates')->json('data.0.break_minutes'))->toBe(45);

    $other = spTenant('CH');
    spActAs($other, spUser($other, 'hr'));
    $this->postJson('/api/v1/shift-planning/templates', $body)->assertCreated();
});
