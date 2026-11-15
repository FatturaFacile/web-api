<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\HumanResources\Models\Position;

require_once __DIR__.'/TalentLearningSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = tlTenant('Acme');
    $this->owner = tlMember($this->tenant, 'owner');
    tlAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tlCycle(array $overrides = []): array
{
    return test()->postJson('/api/v1/talent-learning/compensation/cycles', [
        'name' => 'Merit 2027',
        'fiscal_year' => 2027,
        'currency' => 'eur',
        'starts_on' => '2027-06-01',
        'ends_on' => '2027-06-30',
        'effective_on' => '2027-07-01',
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * A cycle with one pool and the guidelines, opened.
 *
 * @return array{cycle: array<string, mixed>, pool: array<string, mixed>}
 */
function tlOpenCycle(string $budget = '10000', ?string $departmentId = null): array
{
    $cycle = tlCycle();
    $pool = test()->postJson('/api/v1/talent-learning/compensation/pools', ['cycle_id' => $cycle['id'], 'name' => 'Everybody', 'budget_amount' => $budget, 'department_id' => $departmentId])->assertCreated()->json('data');
    test()->putJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/guidelines", ['guidelines' => [
        ['performance_rating' => 3, 'min_percent' => 2, 'max_percent' => 4],
        ['performance_rating' => 5, 'min_percent' => 5, 'max_percent' => 8],
    ]])->assertOk();
    test()->postJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/open")->assertOk()->assertJsonPath('data.status', 'open');

    return ['cycle' => $cycle, 'pool' => $pool];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function tlProposal(string $cycleId, string $poolId, string $employeeId, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/talent-learning/compensation/proposals', [
        'cycle_id' => $cycleId,
        'budget_pool_id' => $poolId,
        'employee_id' => $employeeId,
        'performance_rating' => 3,
        'increase_percent' => 3,
        ...$overrides,
    ]);
}

test('planning is closed to ordinary members and open to HR', function (): void {
    tlAct(tlMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/succession/positions')->assertForbidden();
    $this->getJson('/api/v1/talent-learning/compensation/proposals')->assertForbidden();
    $this->getJson('/api/v1/talent-learning/planning/dashboard')->assertForbidden();

    tlAct(tlMember($this->tenant, 'hr'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/succession/positions')->assertOk();
    tlAct(tlMember($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/succession/positions')->assertForbidden();
});

test('a critical position takes its title from the HR position and a position is critical once', function (): void {
    $position = Position::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Head of Finance']);

    $created = $this->postJson('/api/v1/talent-learning/succession/positions', ['position_id' => $position->id, 'criticality' => 'critical'])
        ->assertCreated()->assertJsonPath('data.title', 'Head of Finance')->assertJsonPath('data.coverage', 'uncovered')->json('data');

    $this->postJson('/api/v1/talent-learning/succession/positions', ['position_id' => $position->id])->assertUnprocessable()->assertJsonValidationErrors('position_id');
    $this->postJson('/api/v1/talent-learning/succession/positions', ['criticality' => 'high'])->assertUnprocessable()->assertJsonValidationErrors('title');
    $this->postJson('/api/v1/talent-learning/succession/positions', ['title' => 'CTO', 'criticality' => 'extreme'])->assertUnprocessable()->assertJsonValidationErrors('criticality');

    $this->putJson("/api/v1/talent-learning/succession/positions/{$created['id']}", ['status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
    $this->postJson('/api/v1/talent-learning/succession/positions', ['position_id' => $position->id])->assertCreated();
});

test('succession positions of another company are invisible and its employees are refused', function (): void {
    $other = tlTenant('Other');
    $foreignEmployee = tlEmployee($other);
    $foreignPosition = Position::factory()->create(['tenant_id' => $other->id]);

    $this->postJson('/api/v1/talent-learning/succession/positions', ['position_id' => $foreignPosition->id])->assertUnprocessable();
    $this->postJson('/api/v1/talent-learning/succession/positions', ['title' => 'CFO', 'incumbent_employee_id' => $foreignEmployee->id])->assertUnprocessable()->assertJsonValidationErrors('incumbent_employee_id');

    $mine = $this->postJson('/api/v1/talent-learning/succession/positions', ['title' => 'CFO'])->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/succession/positions/{$mine['id']}/candidates", ['employee_id' => $foreignEmployee->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');

    tlAct(tlMember($other, 'owner'), $other);
    $this->getJson("/api/v1/talent-learning/succession/positions/{$mine['id']}")->assertNotFound();
    $this->getJson('/api/v1/talent-learning/succession/positions')->assertOk()->assertJsonCount(0, 'data');
    $this->putJson("/api/v1/talent-learning/succession/positions/{$mine['id']}", ['title' => 'Hijacked'])->assertNotFound();
});

test('successor candidates follow the readiness rules and decide the coverage of a position', function (): void {
    $incumbent = tlEmployee($this->tenant);
    $ready = tlEmployee($this->tenant);
    $later = tlEmployee($this->tenant);
    $position = $this->postJson('/api/v1/talent-learning/succession/positions', ['title' => 'Plant manager', 'incumbent_employee_id' => $incumbent->id, 'criticality' => 'critical'])->assertCreated()->json('data');
    $candidates = "/api/v1/talent-learning/succession/positions/{$position['id']}/candidates";

    $this->postJson($candidates, ['employee_id' => $incumbent->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    $this->postJson($candidates, ['employee_id' => $later->id, 'readiness' => 'ready_3_plus_years'])->assertCreated()->assertJsonPath('data.ranking', 1);
    $this->getJson("/api/v1/talent-learning/succession/positions/{$position['id']}")->assertJsonPath('data.coverage', 'uncovered');

    $candidate = $this->postJson($candidates, ['employee_id' => $ready->id, 'readiness' => 'ready_now', 'risk_of_loss' => 'high', 'potential' => 'high'])
        ->assertCreated()->assertJsonPath('data.ranking', 2)->assertJsonPath('data.employee.full_name', $ready->full_name)->json('data');
    $this->postJson($candidates, ['employee_id' => $ready->id])->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    $this->postJson($candidates, ['employee_id' => $ready->id, 'readiness' => 'someday'])->assertUnprocessable();

    $this->getJson("/api/v1/talent-learning/succession/positions/{$position['id']}")->assertJsonPath('data.coverage', 'at_risk')->assertJsonCount(2, 'data.candidates');

    $this->putJson("/api/v1/talent-learning/succession/candidates/{$candidate['id']}", ['risk_of_loss' => 'low'])->assertOk();
    $this->getJson('/api/v1/talent-learning/succession/positions?coverage=covered')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/talent-learning/succession/positions?coverage=uncovered')->assertJsonCount(0, 'data');

    $this->getJson('/api/v1/talent-learning/planning/dashboard')->assertOk()
        ->assertJsonPath('data.succession.positions_total', 1)
        ->assertJsonPath('data.succession.coverage.covered', 1)
        ->assertJsonPath('data.succession.candidates_by_readiness.ready_now', 1)
        ->assertJsonPath('data.succession.high_risk_of_loss_candidates', 0);

    $this->deleteJson("/api/v1/talent-learning/succession/candidates/{$candidate['id']}")->assertNoContent();
    $this->putJson("/api/v1/talent-learning/succession/positions/{$position['id']}", ['status' => 'archived'])->assertOk();
    $this->postJson($candidates, ['employee_id' => tlEmployee($this->tenant)->id])->assertUnprocessable();
    $this->deleteJson("/api/v1/talent-learning/succession/positions/{$position['id']}")->assertNoContent();
    $this->getJson("/api/v1/talent-learning/succession/positions/{$position['id']}")->assertNotFound();
});

test('a cycle needs pools and guidelines to open and is validated', function (): void {
    $this->postJson('/api/v1/talent-learning/compensation/cycles', ['name' => 'x', 'fiscal_year' => 2027, 'currency' => 'EUR', 'starts_on' => '2027-06-10', 'ends_on' => '2027-06-01', 'effective_on' => '2027-07-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('ends_on');

    $cycle = tlCycle();
    $this->assertSame('EUR', $cycle['currency']);
    $this->postJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/open")->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->postJson('/api/v1/talent-learning/compensation/pools', ['cycle_id' => $cycle['id'], 'name' => 'All', 'budget_amount' => 1000])->assertCreated();
    $this->postJson('/api/v1/talent-learning/compensation/pools', ['cycle_id' => $cycle['id'], 'name' => 'All', 'budget_amount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/open")->assertUnprocessable();

    $this->putJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/guidelines", ['guidelines' => [
        ['performance_rating' => 3, 'min_percent' => 4, 'max_percent' => 2],
    ]])->assertUnprocessable();
    $this->putJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/guidelines", ['guidelines' => [
        ['performance_rating' => 3, 'min_percent' => 1, 'max_percent' => 2],
        ['performance_rating' => 3, 'min_percent' => 1, 'max_percent' => 2],
    ]])->assertUnprocessable();
    $this->putJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/guidelines", ['guidelines' => [['performance_rating' => 3, 'min_percent' => 1, 'max_percent' => 2]]])
        ->assertOk()->assertJsonCount(1, 'data.guidelines');

    $this->postJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/open")->assertOk();
    $this->putJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}", ['currency' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors('currency');
    $this->deleteJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}")->assertUnprocessable();

    $draft = tlCycle(['name' => 'Draft']);
    $this->deleteJson("/api/v1/talent-learning/compensation/cycles/{$draft['id']}")->assertNoContent();
});

test('a merit proposal is computed from the HR salary and outside the guideline it is an exception', function (): void {
    ['cycle' => $cycle, 'pool' => $pool] = tlOpenCycle();
    $employee = tlEmployee($this->tenant, ['base_annual_salary' => '40000.00']);

    $proposal = tlProposal($cycle['id'], $pool['id'], $employee->id)->assertCreated()
        ->assertJsonPath('data.current_salary', '40000.00')
        ->assertJsonPath('data.increase_percent', '3.00')
        ->assertJsonPath('data.increase_amount', '1200.00')
        ->assertJsonPath('data.new_salary', '41200.00')
        ->assertJsonPath('data.is_exception', false)
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.employee.full_name', $employee->full_name)
        ->json('data');

    $this->postJson('/api/v1/talent-learning/compensation/proposals', ['cycle_id' => $cycle['id'], 'budget_pool_id' => $pool['id'], 'employee_id' => $employee->id, 'performance_rating' => 3, 'increase_percent' => 3])
        ->assertUnprocessable()->assertJsonValidationErrors('employee_id');

    $this->putJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}", ['increase_percent' => 6])->assertUnprocessable()->assertJsonValidationErrors('justification');
    $this->putJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}", ['increase_percent' => 6, 'justification' => 'Retention'])
        ->assertOk()->assertJsonPath('data.is_exception', true)->assertJsonPath('data.increase_amount', '2400.00');
    $this->putJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}", ['increase_percent' => 3, 'justification' => null])
        ->assertOk()->assertJsonPath('data.is_exception', false);

    $other = tlEmployee($this->tenant);
    tlProposal($cycle['id'], $pool['id'], $other->id, ['performance_rating' => 1, 'increase_percent' => 1])->assertUnprocessable()->assertJsonValidationErrors('justification');
    tlProposal($cycle['id'], $pool['id'], $other->id, ['increase_percent' => 101])->assertUnprocessable()->assertJsonValidationErrors('increase_percent');
});

test('a merit proposal is refused for the wrong employee, pool, currency or cycle state', function (): void {
    $department = tlDepartment($this->tenant, 'Sales');
    ['cycle' => $cycle, 'pool' => $pool] = tlOpenCycle('10000', $department->id);
    $inDepartment = tlEmployee($this->tenant, ['department_id' => $department->id]);
    $elsewhere = tlEmployee($this->tenant);
    $dollar = tlEmployee($this->tenant, ['department_id' => $department->id, 'currency' => 'USD']);
    $terminated = tlEmployee($this->tenant, ['department_id' => $department->id, 'employment_status' => 'terminated']);
    $foreign = tlEmployee(tlTenant('Foreign'));

    tlProposal($cycle['id'], $pool['id'], $inDepartment->id)->assertCreated();
    tlProposal($cycle['id'], $pool['id'], $elsewhere->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    tlProposal($cycle['id'], $pool['id'], $dollar->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    tlProposal($cycle['id'], $pool['id'], $terminated->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
    tlProposal($cycle['id'], $pool['id'], $foreign->id)->assertUnprocessable()->assertJsonValidationErrors('employee_id');

    $draftCycle = tlCycle(['name' => 'Next']);
    tlProposal($draftCycle['id'], $pool['id'], $inDepartment->id)->assertUnprocessable();
    $foreignPool = test()->postJson('/api/v1/talent-learning/compensation/pools', ['cycle_id' => $draftCycle['id'], 'name' => 'Other', 'budget_amount' => 10])->assertCreated()->json('data');
    tlProposal($cycle['id'], $foreignPool['id'], $inDepartment->id)->assertUnprocessable();

    $this->postJson('/api/v1/talent-learning/compensation/pools', ['cycle_id' => $cycle['id'], 'name' => 'Ghost', 'budget_amount' => 5, 'department_id' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonValidationErrors('department_id');
});

test('the budget of a pool cannot be exceeded and decisions release or confirm it', function (): void {
    ['cycle' => $cycle, 'pool' => $pool] = tlOpenCycle('2000');
    $first = tlEmployee($this->tenant, ['base_annual_salary' => '40000.00']);
    $second = tlEmployee($this->tenant, ['base_annual_salary' => '40000.00']);

    $one = tlProposal($cycle['id'], $pool['id'], $first->id)->assertCreated()->json('data');
    $two = tlProposal($cycle['id'], $pool['id'], $second->id)->assertCreated()->json('data');

    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$one['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$one['id']}/submit")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$two['id']}/submit")->assertUnprocessable()->assertJsonValidationErrors('budget_pool_id');
    $this->putJson("/api/v1/talent-learning/compensation/proposals/{$one['id']}", ['increase_percent' => 2])->assertUnprocessable();
    $this->deleteJson("/api/v1/talent-learning/compensation/proposals/{$one['id']}")->assertUnprocessable();
    $this->putJson("/api/v1/talent-learning/compensation/pools/{$pool['id']}", ['name' => 'Everybody', 'budget_amount' => 1000])->assertUnprocessable()->assertJsonValidationErrors('budget_amount');

    $this->getJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/summary")->assertOk()
        ->assertJsonPath('data.budget_amount', '2000.00')
        ->assertJsonPath('data.committed_amount', '1200.00')
        ->assertJsonPath('data.approved_amount', '0.00')
        ->assertJsonPath('data.remaining_amount', '800.00')
        ->assertJsonPath('data.proposals_by_status.submitted', 1)
        ->assertJsonPath('data.pools.0.utilization_percent', 60);

    $this->postJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/close")->assertUnprocessable()->assertJsonValidationErrors('status');

    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$one['id']}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('decision_note');
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$one['id']}/reject", ['decision_note' => 'Not this year'])
        ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decided_by_user_id', $this->owner->id);

    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$two['id']}/submit")->assertOk();
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$two['id']}/approve", ['decision_note' => 'Well deserved'])
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decision_note', 'Well deserved');
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$two['id']}/approve")->assertUnprocessable();

    tlProposal($cycle['id'], $pool['id'], $first->id)->assertCreated();

    $this->getJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/summary")->assertOk()
        ->assertJsonPath('data.approved_amount', '1200.00')
        ->assertJsonPath('data.approved_increase_percent', 3)
        ->assertJsonPath('data.proposals_by_status.rejected', 1)
        ->assertJsonPath('data.proposals_by_status.approved', 1)
        ->assertJsonPath('data.proposals_by_status.draft', 1);

    $this->getJson('/api/v1/talent-learning/compensation/proposals?status=approved')->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/talent-learning/compensation/proposals?employee_id={$first->id}")->assertJsonCount(2, 'data');
    $this->postJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}", ['name' => 'Renamed'])->assertUnprocessable();
    $this->getJson('/api/v1/talent-learning/planning/dashboard')->assertOk()->assertJsonPath('data.compensation.current_cycle.approved_amount', '1200.00');
});

test('only owners, administrators and granted members approve merit proposals', function (): void {
    ['cycle' => $cycle, 'pool' => $pool] = tlOpenCycle();
    $proposal = tlProposal($cycle['id'], $pool['id'], tlEmployee($this->tenant)->id)->assertCreated()->json('data');
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}/submit")->assertOk();

    tlAct(tlMember($this->tenant, 'hr'), $this->tenant);
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}/approve")->assertForbidden();
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}/reject", ['decision_note' => 'no'])->assertForbidden();

    tlAct(tlMember($this->tenant, 'payroll', ['talent_planning.approve']), $this->tenant);
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
});

test('compensation data of another company is invisible', function (): void {
    ['cycle' => $cycle, 'pool' => $pool] = tlOpenCycle();
    $proposal = tlProposal($cycle['id'], $pool['id'], tlEmployee($this->tenant)->id)->assertCreated()->json('data');

    $other = tlTenant('Other');
    tlAct(tlMember($other, 'owner'), $other);

    $this->getJson('/api/v1/talent-learning/compensation/cycles')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}")->assertNotFound();
    $this->getJson("/api/v1/talent-learning/compensation/cycles/{$cycle['id']}/summary")->assertNotFound();
    $this->getJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}")->assertNotFound();
    $this->postJson("/api/v1/talent-learning/compensation/proposals/{$proposal['id']}/approve")->assertNotFound();
    $this->postJson('/api/v1/talent-learning/compensation/pools', ['cycle_id' => $cycle['id'], 'name' => 'Intruder', 'budget_amount' => 1])->assertUnprocessable()->assertJsonValidationErrors('cycle_id');
});

test('the lookups list active employees and positions without pay data', function (): void {
    $employee = tlEmployee($this->tenant, ['full_name' => 'Giulia Verdi', 'first_name' => 'Giulia', 'last_name' => 'Verdi']);
    tlEmployee($this->tenant, ['full_name' => 'Marco Retired', 'employment_status' => 'retired']);
    tlEmployee(tlTenant('Other'), ['full_name' => 'Giulia Foreign']);

    $response = $this->getJson('/api/v1/talent-learning/lookups/employees?search=giul')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $employee->id);
    $this->assertArrayNotHasKey('base_annual_salary', $response->json('data.0'));
    $this->getJson('/api/v1/talent-learning/lookups/employees')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/talent-learning/lookups/employees?limit=500')->assertUnprocessable();

    Position::factory()->create(['tenant_id' => $this->tenant->id, 'title' => 'Treasurer']);
    $this->getJson('/api/v1/talent-learning/lookups/positions?search=treas')->assertOk()->assertJsonCount(1, 'data');

    tlAct(tlMember($this->tenant, 'member'), $this->tenant);
    $this->getJson('/api/v1/talent-learning/lookups/employees')->assertForbidden();
});
