<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\Goal;
use Modules\HumanResources\Models\GoalCheckIn;
use Modules\HumanResources\Models\GoalCycle;
use Modules\HumanResources\Models\GoalKeyResult;
use Modules\HumanResources\Models\Team;

uses(RefreshDatabase::class);

function goalsMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@example.ch', 'password' => bcrypt(Str::random(24)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function goalsApi(?User $user = null, ?Tenant $tenant = null): mixed
{
    Sanctum::actingAs($user ?? test()->manager);

    return test()->withHeader('X-Tenant-Id', ($tenant ?? test()->tenant)->id);
}

/**
 * @param  array<string, mixed>  $values
 */
function goalsCreate(array $values): string
{
    return goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => test()->cycle->id, ...$values])->assertCreated()->json('data.id');
}

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $this->tenant = Tenant::create(['name' => 'Obiettivi SA', 'legal_name' => 'Obiettivi SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->other = Tenant::create(['name' => 'Altra SA', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $this->manager = goalsMember($this->tenant);
    $this->cycle = GoalCycle::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->employee = Employee::factory()->create(['tenant_id' => $this->tenant->id]);
});

test('a goal cycle is created, changed and validated', function (): void {
    $id = goalsApi()->postJson('/api/v1/hr/goal-cycles', ['name' => 'H1 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-06-30'])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    goalsApi()->putJson("/api/v1/hr/goal-cycles/{$id}", ['status' => 'active', 'name' => 'First half 2027'])->assertOk()->assertJsonPath('data.status', 'active');
    goalsApi()->putJson("/api/v1/hr/goal-cycles/{$id}", ['ends_on' => '2026-12-31'])->assertUnprocessable();
    goalsApi()->putJson("/api/v1/hr/goal-cycles/{$id}", ['status' => 'closed'])->assertUnprocessable()->assertJsonValidationErrors('status');
    goalsApi()->postJson('/api/v1/hr/goal-cycles', ['name' => '', 'starts_on' => '2027-02-01', 'ends_on' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'ends_on']);

    expect(goalsApi()->getJson('/api/v1/hr/goal-cycles')->assertOk()->json('data'))->toHaveCount(2);
});

test('the progress of a goal comes from its key results and rolls up to the parent goals', function (): void {
    $company = goalsCreate(['level' => 'company', 'title' => 'Grow revenue']);
    $team = Team::factory()->create(['tenant_id' => $this->tenant->id]);
    $teamGoal = goalsCreate(['level' => 'team', 'team_id' => $team->id, 'parent_goal_id' => $company, 'title' => 'Win new accounts', 'weight' => 3]);
    $personal = goalsApi()->postJson('/api/v1/hr/goals', [
        'goal_cycle_id' => $this->cycle->id,
        'level' => 'employee',
        'employee_id' => $this->employee->id,
        'parent_goal_id' => $teamGoal,
        'title' => 'Close five deals',
        'weight' => 2,
        'due_on' => '2026-12-31',
        'key_results' => [
            ['metric' => 'Deals closed', 'unit' => 'deals', 'start_value' => 0, 'target_value' => 5],
            ['metric' => 'Pipeline value', 'unit' => 'kCHF', 'start_value' => 100, 'target_value' => 300, 'current_value' => 200],
        ],
    ])->assertCreated();

    $personalId = $personal->json('data.id');
    expect((float) $personal->json('data.progress'))->toBe(25.0);

    $keyResult = $personal->json('data.key_results.0.id');
    goalsApi()->postJson("/api/v1/hr/goals/{$personalId}/check-ins", ['key_result_id' => $keyResult, 'value' => 5, 'comment' => 'Fifth deal signed'])
        ->assertCreated()->assertJsonPath('data.progress', '75.00');

    expect((float) Goal::query()->findOrFail($teamGoal)->progress)->toBe(75.0)
        ->and((float) Goal::query()->findOrFail($company)->progress)->toBe(75.0);

    goalsApi()->postJson("/api/v1/hr/goals/{$personalId}/check-ins", ['key_result_id' => $keyResult, 'value' => 9])->assertCreated();
    expect((float) GoalKeyResult::query()->findOrFail($keyResult)->progress)->toBe(100.0);

    $history = goalsApi()->getJson("/api/v1/hr/goals/{$personalId}/check-ins")->assertOk()->json('data');
    expect($history)->toHaveCount(2)->and(GoalCheckIn::query()->count())->toBe(2);

    goalsApi()->getJson("/api/v1/hr/goals/{$personalId}")->assertOk()
        ->assertJsonPath('data.parent.id', $teamGoal)->assertJsonCount(2, 'data.key_results')->assertJsonCount(2, 'data.check_ins');
});

test('a goal without key results takes its progress from the check-ins, and a derived progress cannot be set by hand', function (): void {
    $plain = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Mentor a colleague']);
    goalsApi()->postJson("/api/v1/hr/goals/{$plain}/check-ins", ['progress' => 40])->assertCreated();
    expect((float) Goal::query()->findOrFail($plain)->progress)->toBe(40.0);
    goalsApi()->postJson("/api/v1/hr/goals/{$plain}/check-ins", [])->assertUnprocessable()->assertJsonValidationErrors('progress');
    goalsApi()->postJson("/api/v1/hr/goals/{$plain}/check-ins", ['progress' => 140])->assertUnprocessable();

    $measured = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Ship v2', 'key_results' => [['metric' => 'Releases', 'target_value' => 2]]]);
    goalsApi()->postJson("/api/v1/hr/goals/{$measured}/check-ins", ['progress' => 50])->assertUnprocessable();

    $keyResult = goalsApi()->postJson("/api/v1/hr/goals/{$measured}/key-results", ['metric' => 'Bugs fixed', 'start_value' => 10, 'target_value' => 0, 'current_value' => 5])->assertCreated();
    expect((float) $keyResult->json('data.progress'))->toBe(50.0);
    goalsApi()->putJson('/api/v1/hr/goal-key-results/'.$keyResult->json('data.id'), ['current_value' => 0])->assertOk();
    expect((float) Goal::query()->findOrFail($measured)->progress)->toBe(50.0);
    goalsApi()->deleteJson('/api/v1/hr/goal-key-results/'.$keyResult->json('data.id'))->assertNoContent();
    expect((float) Goal::query()->findOrFail($measured)->progress)->toBe(0.0);
});

test('the cascade is validated: levels, cycle, owner and loops', function (): void {
    $company = goalsCreate(['level' => 'company', 'title' => 'Company goal']);
    $personal = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'parent_goal_id' => $company, 'title' => 'Own goal']);
    $team = Team::factory()->create(['tenant_id' => $this->tenant->id]);
    $otherCycle = GoalCycle::factory()->create(['tenant_id' => $this->tenant->id]);

    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'employee', 'title' => 'No owner'])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'team', 'title' => 'No team'])->assertUnprocessable()->assertJsonValidationErrors('team_id');
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'company', 'title' => 'Bad', 'parent_goal_id' => $personal])->assertUnprocessable();
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'team', 'team_id' => $team->id, 'title' => 'Same level', 'parent_goal_id' => goalsCreate(['level' => 'team', 'team_id' => $team->id, 'title' => 'Peer'])])->assertUnprocessable();
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $otherCycle->id, 'level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Elsewhere', 'parent_goal_id' => $company])->assertUnprocessable();
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'bogus', 'title' => 'x', 'weight' => 0])->assertUnprocessable()->assertJsonValidationErrors(['level', 'weight']);

    goalsApi()->putJson("/api/v1/hr/goals/{$company}", ['parent_goal_id' => $personal])->assertUnprocessable();
    goalsApi()->putJson("/api/v1/hr/goals/{$personal}", ['level' => 'company'])->assertUnprocessable();
    goalsApi()->putJson("/api/v1/hr/goals/{$personal}", ['title' => 'Renamed', 'weight' => 2.5, 'parent_goal_id' => null])->assertOk()->assertJsonPath('data.title', 'Renamed')->assertJsonPath('data.parent_goal_id', null);

    $filtered = goalsApi()->getJson('/api/v1/hr/goals?employee_id='.$this->employee->id)->assertOk()->json('data');
    expect($filtered)->toHaveCount(1);
    expect(goalsApi()->getJson('/api/v1/hr/teams')->assertOk()->json('data'))->toHaveCount(1);
    expect(goalsApi()->getJson('/api/v1/hr/goals?level=company&status=active')->json('data'))->toHaveCount(1);
});

test('deleting a goal detaches its child goals and recomputes the parent', function (): void {
    $company = goalsCreate(['level' => 'company', 'title' => 'Parent']);
    $child = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'parent_goal_id' => $company, 'title' => 'Child', 'key_results' => [['metric' => 'M', 'target_value' => 10, 'current_value' => 10]]]);

    expect((float) Goal::query()->findOrFail($company)->progress)->toBe(100.0);
    goalsApi()->deleteJson("/api/v1/hr/goals/{$child}")->assertNoContent();
    expect(Goal::query()->find($child))->toBeNull();

    $orphan = goalsCreate(['level' => 'team', 'team_id' => Team::factory()->create(['tenant_id' => $this->tenant->id])->id, 'parent_goal_id' => $company, 'title' => 'Orphan-to-be']);
    goalsApi()->deleteJson("/api/v1/hr/goals/{$company}")->assertNoContent();
    expect(Goal::query()->findOrFail($orphan)->parent_goal_id)->toBeNull();
});

test('closing a cycle freezes its goals', function (): void {
    $done = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Done', 'key_results' => [['metric' => 'M', 'target_value' => 1, 'current_value' => 1]]]);
    $open = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Open', 'key_results' => [['metric' => 'M', 'target_value' => 10]]]);
    $draft = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Draft', 'status' => 'draft']);

    goalsApi()->postJson("/api/v1/hr/goal-cycles/{$this->cycle->id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    expect(Goal::query()->findOrFail($done)->status)->toBe('completed')
        ->and(Goal::query()->findOrFail($open)->status)->toBe('active')
        ->and(Goal::query()->findOrFail($draft)->status)->toBe('cancelled')
        ->and(GoalCycle::query()->findOrFail($this->cycle->id)->closed_at)->not->toBeNull();

    goalsApi()->postJson("/api/v1/hr/goal-cycles/{$this->cycle->id}/close")->assertStatus(409);
    goalsApi()->postJson("/api/v1/hr/goals/{$open}/check-ins", ['progress' => 10])->assertStatus(409);
    goalsApi()->putJson("/api/v1/hr/goals/{$open}", ['title' => 'Late edit'])->assertStatus(409);
    goalsApi()->putJson("/api/v1/hr/goal-cycles/{$this->cycle->id}", ['name' => 'Late edit'])->assertStatus(409);
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'company', 'title' => 'Late goal'])->assertStatus(409);
    goalsApi()->deleteJson("/api/v1/hr/goals/{$open}")->assertStatus(409);
});

test('a completed goal takes no more check-ins', function (): void {
    $id = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Wrapped up']);
    goalsApi()->putJson("/api/v1/hr/goals/{$id}", ['status' => 'completed'])->assertOk();
    goalsApi()->postJson("/api/v1/hr/goals/{$id}/check-ins", ['progress' => 10])->assertStatus(409);
});

test('the goal progress of an employee is the weighted average of the goals, a figure for the performance review', function (): void {
    expect(goalsApi()->getJson("/api/v1/hr/employees/{$this->employee->id}/goal-progress")->assertOk()->json('data.progress'))->toBeNull();

    goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Heavy', 'weight' => 3, 'key_results' => [['metric' => 'M', 'target_value' => 10, 'current_value' => 10]]]);
    goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Light', 'weight' => 1, 'key_results' => [['metric' => 'M', 'target_value' => 10]]]);
    goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Ignored', 'weight' => 10, 'status' => 'draft']);

    expect((float) goalsApi()->getJson("/api/v1/hr/employees/{$this->employee->id}/goal-progress?goal_cycle_id={$this->cycle->id}")->assertOk()->json('data.progress'))->toBe(75.0);
});

test('goals are isolated between companies and need the HR module role', function (): void {
    $id = goalsCreate(['level' => 'employee', 'employee_id' => $this->employee->id, 'title' => 'Mine', 'key_results' => [['metric' => 'M', 'target_value' => 10]]]);
    $keyResult = Goal::query()->findOrFail($id)->keyResults()->firstOrFail()->id;
    $otherManager = goalsMember($this->other);
    $foreignEmployee = Employee::factory()->create(['tenant_id' => $this->other->id]);
    $foreignCycle = GoalCycle::factory()->create(['tenant_id' => $this->other->id]);

    goalsApi($otherManager, $this->other)->getJson("/api/v1/hr/goals/{$id}")->assertNotFound();
    goalsApi($otherManager, $this->other)->putJson("/api/v1/hr/goals/{$id}", ['title' => 'Stolen'])->assertNotFound();
    goalsApi($otherManager, $this->other)->deleteJson("/api/v1/hr/goals/{$id}")->assertNotFound();
    goalsApi($otherManager, $this->other)->postJson("/api/v1/hr/goals/{$id}/check-ins", ['progress' => 5])->assertNotFound();
    goalsApi($otherManager, $this->other)->putJson("/api/v1/hr/goal-key-results/{$keyResult}", ['current_value' => 5])->assertNotFound();
    goalsApi($otherManager, $this->other)->postJson("/api/v1/hr/goal-cycles/{$this->cycle->id}/close")->assertNotFound();
    goalsApi($otherManager, $this->other)->getJson("/api/v1/hr/employees/{$this->employee->id}/goal-progress")->assertNotFound();
    expect(goalsApi($otherManager, $this->other)->getJson('/api/v1/hr/goals')->assertOk()->json('data'))->toBe([])
        ->and(goalsApi($otherManager, $this->other)->getJson('/api/v1/hr/goal-cycles')->assertOk()->json('data'))->toHaveCount(1);

    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $foreignCycle->id, 'level' => 'company', 'title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('goal_cycle_id');
    goalsApi()->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'employee', 'employee_id' => $foreignEmployee->id, 'title' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    goalsApi()->postJson("/api/v1/hr/goals/{$id}/check-ins", ['key_result_id' => GoalKeyResult::factory()->create(['tenant_id' => $this->other->id])->id, 'value' => 1])->assertUnprocessable();

    $member = goalsMember($this->tenant, 'member');
    goalsApi($member)->getJson('/api/v1/hr/goals')->assertForbidden();
    goalsApi($member)->postJson('/api/v1/hr/goals', ['goal_cycle_id' => $this->cycle->id, 'level' => 'company', 'title' => 'x'])->assertForbidden();
    goalsApi($member)->postJson("/api/v1/hr/goal-cycles/{$this->cycle->id}/close")->assertForbidden();
    goalsApi($member)->getJson('/api/v1/hr/goal-cycles')->assertForbidden();
});

test('unauthenticated requests are refused', function (): void {
    $this->getJson('/api/v1/hr/goals')->assertUnauthorized();
});
