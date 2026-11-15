<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Planning\Contracts\DemandForecastSource;
use Modules\Planning\Models\PlanningCapacityConstraint;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningResource;
use Modules\Planning\Models\PlanningScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = plnTenant('Manifattura');
    $this->owner = plnMember($this->tenant, 'owner');
    plnAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function plnTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function plnMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@planning-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function plnAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plnScenario(array $overrides = []): array
{
    return test()->postJson('/api/v1/planning/scenarios', [
        'name' => 'Plan '.Str::random(5),
        'scenario_type' => 'baseline',
        'horizon_start' => '2028-01-01',
        'horizon_end' => '2028-04-30',
        ...$overrides,
    ])->assertCreated()->json('data');
}

function plnLine(string $scenarioId, string $type, string $item, string $period, string $quantity): TestResponse
{
    return test()->postJson("/api/v1/planning/scenarios/{$scenarioId}/lines", ['line_type' => $type, 'item_code' => $item, 'period' => $period, 'quantity' => $quantity]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function plnResource(array $overrides = []): array
{
    return test()->postJson('/api/v1/planning/resources', ['code' => 'RES-'.Str::upper(Str::random(4)), 'name' => 'Assembly', 'unit' => 'hours', 'default_capacity' => '100', ...$overrides])->assertCreated()->json('data');
}

test('a company has one baseline and the horizon is validated', function (): void {
    $baseline = plnScenario();
    expect($baseline['status'])->toBe('draft')->and($baseline['horizon_start'])->toBe('2028-01-01');

    $this->postJson('/api/v1/planning/scenarios', ['name' => 'Second', 'scenario_type' => 'baseline', 'horizon_start' => '2028-01-01', 'horizon_end' => '2028-03-31'])
        ->assertUnprocessable()->assertJsonValidationErrors('scenario_type');
    plnScenario(['name' => 'What-if', 'scenario_type' => 'what_if']);

    $this->postJson('/api/v1/planning/scenarios', ['name' => 'Backwards', 'scenario_type' => 'what_if', 'horizon_start' => '2028-05-01', 'horizon_end' => '2028-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('horizon_end');
    $this->postJson('/api/v1/planning/scenarios', ['name' => 'Too long', 'scenario_type' => 'what_if', 'horizon_start' => '2028-01-01', 'horizon_end' => '2032-01-01'])
        ->assertUnprocessable()->assertJsonValidationErrors('horizon_end');
    $this->postJson('/api/v1/planning/scenarios', ['scenario_type' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'scenario_type', 'horizon_start']);

    $this->getJson('/api/v1/planning/scenarios?scenario_type=what_if')->assertOk()->assertJsonCount(1, 'data');
});

test('saving a line twice replaces the quantity and the month must be inside the horizon', function (): void {
    $scenario = plnScenario();
    $first = plnLine($scenario['id'], 'demand', 'P-1', '2028-02-17', '100')->assertCreated()->json('data');
    expect($first['period'])->toBe('2028-02-01');

    $second = plnLine($scenario['id'], 'demand', 'P-1', '2028-02-03', '150.5')->assertCreated()->json('data');
    expect($second['id'])->toBe($first['id'])->and($second['quantity'])->toBe('150.500');
    expect(PlanningPlanLine::query()->where('scenario_id', $scenario['id'])->count())->toBe(1);

    plnLine($scenario['id'], 'demand', 'P-1', '2028-09-01', '10')->assertUnprocessable()->assertJsonValidationErrors('period');
    plnLine($scenario['id'], 'demand', 'P-1', '2027-12-31', '10')->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/lines", ['line_type' => 'supply', 'period' => '2028-01-01', 'quantity' => '5'])
        ->assertUnprocessable()->assertJsonValidationErrors('item_code');
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/lines", ['line_type' => 'supply', 'item_id' => (string) Str::uuid(), 'period' => '2028-01-01', 'quantity' => '5'])
        ->assertUnprocessable()->assertJsonValidationErrors('item_id');
    plnLine($scenario['id'], 'sideways', 'P-1', '2028-01-01', '5')->assertUnprocessable()->assertJsonValidationErrors('line_type');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-01-01', '-5')->assertUnprocessable()->assertJsonValidationErrors('quantity');

    $this->putJson("/api/v1/planning/lines/{$first['id']}", ['quantity' => '90'])->assertOk()->assertJsonPath('data.quantity', '90.000');
    $this->deleteJson("/api/v1/planning/lines/{$first['id']}")->assertNoContent();
    $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/lines")->assertOk()->assertJsonCount(0, 'data');
});

test('the balance computes the gap per month with and without carry-over', function (): void {
    $scenario = plnScenario(['horizon_end' => '2028-03-31']);
    plnLine($scenario['id'], 'demand', 'P-1', '2028-01-01', '100');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-01-01', '60');
    plnLine($scenario['id'], 'demand', 'P-1', '2028-02-01', '50');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-02-01', '120');
    plnLine($scenario['id'], 'demand', 'P-1', '2028-03-01', '10');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-03-01', '10');
    plnLine($scenario['id'], 'supply', 'P-2', '2028-01-01', '30');

    $carried = $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/balance")->assertOk()->json('data');
    $item = collect($carried['items'])->firstWhere('item_code', 'P-1');
    expect($carried['periods'])->toBe(['2028-01-01', '2028-02-01', '2028-03-01'])
        ->and(array_column($item['periods'], 'gap'))->toBe(['-40.000', '70.000', '0.000'])
        ->and(array_column($item['periods'], 'balance'))->toBe(['-40.000', '30.000', '30.000'])
        ->and(array_column($item['periods'], 'status'))->toBe(['shortage', 'surplus', 'surplus'])
        ->and($item['total_gap'])->toBe('30.000')
        ->and($item['max_shortage'])->toBe('40.000')
        ->and($item['shortage_periods'])->toBe(1)
        ->and($carried['totals']['total_demand'])->toBe('160.000')
        ->and($carried['totals']['total_supply'])->toBe('220.000')
        ->and($carried['totals']['shortage_items'])->toBe(1)
        ->and($carried['totals']['surplus_items'])->toBe(2);

    $standalone = $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/balance?carry_over=0")->assertOk()->json('data');
    $item = collect($standalone['items'])->firstWhere('item_code', 'P-1');
    expect(array_column($item['periods'], 'balance'))->toBe(['-40.000', '70.000', '0.000'])->and($item['ending_gap'])->toBe('0.000');
});

test('the capacity check loads resources from the supply plan and honours the scenario constraints', function (): void {
    $scenario = plnScenario(['horizon_end' => '2028-02-29']);
    $resource = plnResource(['code' => 'ASM', 'default_capacity' => '100']);
    $this->postJson("/api/v1/planning/resources/{$resource['id']}/rates", ['item_code' => 'P-1', 'load_per_unit' => '0.5'])->assertCreated();
    $this->postJson("/api/v1/planning/resources/{$resource['id']}/rates", ['item_code' => 'P-1', 'load_per_unit' => '0.7'])->assertUnprocessable()->assertJsonValidationErrors('item_code');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-01-01', '150');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-02-01', '300');
    plnLine($scenario['id'], 'demand', 'P-1', '2028-02-01', '999');

    $check = $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/capacity")->assertOk()->json('data');
    expect($check['rows'])->toHaveCount(2)
        ->and($check['rows'][0]['load'])->toBe('75.000')
        ->and($check['rows'][0]['utilization_percent'])->toBe('75.0')
        ->and($check['rows'][0]['is_overloaded'])->toBeFalse()
        ->and($check['rows'][1]['load'])->toBe('150.000')
        ->and($check['rows'][1]['overload'])->toBe('50.000')
        ->and($check['rows'][1]['is_overloaded'])->toBeTrue()
        ->and($check['summary']['feasible'])->toBeFalse()
        ->and($check['summary']['overloaded_count'])->toBe(1)
        ->and($check['summary']['max_utilization_percent'])->toBe('150.0');

    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/constraints", ['resource_id' => $resource['id'], 'period' => '2028-02-10', 'available_capacity' => '200'])->assertCreated();
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/constraints", ['resource_id' => $resource['id'], 'period' => '2028-02-01', 'available_capacity' => '160'])->assertCreated();
    expect(PlanningCapacityConstraint::query()->where('scenario_id', $scenario['id'])->count())->toBe(1);

    $check = $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/capacity")->json('data');
    expect($check['rows'][1]['available'])->toBe('160.000')->and($check['rows'][1]['source'])->toBe('constraint')->and($check['summary']['feasible'])->toBeTrue();

    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/constraints", ['resource_id' => $resource['id'], 'period' => '2029-01-01', 'available_capacity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/constraints", ['resource_id' => (string) Str::uuid(), 'period' => '2028-01-01', 'available_capacity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('resource_id');
});

test('the S&OP cycle runs draft, review, approved, locked and freezes the plan', function (): void {
    $scenario = plnScenario();
    $id = $scenario['id'];

    $this->postJson("/api/v1/planning/scenarios/{$id}/submit")->assertUnprocessable()->assertJsonValidationErrors('status');
    plnLine($id, 'demand', 'P-1', '2028-01-01', '10')->assertCreated();
    $this->postJson("/api/v1/planning/scenarios/{$id}/approve")->assertUnprocessable();

    $this->postJson("/api/v1/planning/scenarios/{$id}/submit", ['comment' => 'Ready'])->assertOk()->assertJsonPath('data.status', 'review')->assertJsonPath('data.approvals.0.action', 'submit');
    plnLine($id, 'demand', 'P-1', '2028-02-01', '10')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->putJson("/api/v1/planning/scenarios/{$id}", ['name' => 'Renamed'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->deleteJson("/api/v1/planning/scenarios/{$id}")->assertUnprocessable();
    $this->postJson("/api/v1/planning/scenarios/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson("/api/v1/planning/scenarios/{$id}/reject", ['comment' => 'Demand too low'])->assertOk()->assertJsonPath('data.status', 'draft');
    plnLine($id, 'demand', 'P-1', '2028-02-01', '10')->assertCreated();

    $this->postJson("/api/v1/planning/scenarios/{$id}/submit")->assertOk();
    $this->postJson("/api/v1/planning/scenarios/{$id}/publish")->assertUnprocessable();
    $this->postJson("/api/v1/planning/scenarios/{$id}/lock")->assertUnprocessable();
    $approved = $this->postJson("/api/v1/planning/scenarios/{$id}/approve")->assertOk()->json('data');
    expect($approved['status'])->toBe('approved')->and($approved['approved_by'])->toBe($this->owner->id);
    $this->postJson("/api/v1/planning/scenarios/{$id}/submit")->assertUnprocessable();

    $this->postJson("/api/v1/planning/scenarios/{$id}/lock")->assertOk()->assertJsonPath('data.status', 'locked');
    plnLine($id, 'demand', 'P-1', '2028-03-01', '10')->assertUnprocessable();
    $this->postJson("/api/v1/planning/scenarios/{$id}/reject", ['comment' => 'x'])->assertUnprocessable();
    $this->putJson("/api/v1/planning/scenarios/{$id}", ['name' => 'Renamed'])->assertUnprocessable();

    $locked = PlanningScenario::query()->findOrFail($id);
    $locked->name = 'Sneaky';
    expect(fn () => $locked->save())->toThrow(LogicException::class);

    $actions = collect($this->getJson("/api/v1/planning/scenarios/{$id}")->json('data.approvals'))->pluck('action')->all();
    expect($actions)->toBe(['submit', 'reject', 'submit', 'approve', 'lock']);
});

test('only approvers decide the cycle and read-only roles never write', function (): void {
    $scenario = plnScenario();
    plnLine($scenario['id'], 'demand', 'P-1', '2028-01-01', '10');

    $planner = plnMember($this->tenant, 'member');
    plnAct($planner, $this->tenant);
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/submit")->assertOk();
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/approve")->assertForbidden();
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/reject", ['comment' => 'no'])->assertForbidden();

    $granted = User::create(['name' => 'Granted '.Str::random(4), 'email' => Str::random(10).'@planning-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $granted->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member', 'permissions' => json_encode(['planning.approve'])]);
    plnAct($granted, $this->tenant);
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/approve")->assertOk();

    $viewer = plnMember($this->tenant, 'accountant_readonly');
    plnAct($viewer, $this->tenant);
    $this->getJson('/api/v1/planning/scenarios')->assertOk();
    $this->postJson('/api/v1/planning/scenarios', ['name' => 'Nope', 'scenario_type' => 'what_if', 'horizon_start' => '2028-01-01', 'horizon_end' => '2028-02-01'])->assertForbidden();
});

test('approving an overloaded plan needs an explicit commented acceptance and publishing versions the plan', function (): void {
    $scenario = plnScenario(['horizon_end' => '2028-02-29']);
    $id = $scenario['id'];
    $resource = plnResource(['default_capacity' => '10']);
    $this->postJson("/api/v1/planning/resources/{$resource['id']}/rates", ['item_code' => 'P-1', 'load_per_unit' => '1'])->assertCreated();
    plnLine($id, 'supply', 'P-1', '2028-01-01', '50');
    plnLine($id, 'demand', 'P-1', '2028-01-01', '70');
    $this->postJson("/api/v1/planning/scenarios/{$id}/submit")->assertOk();

    $this->postJson("/api/v1/planning/scenarios/{$id}/approve")->assertUnprocessable()->assertJsonValidationErrors('accept_overload');
    $this->postJson("/api/v1/planning/scenarios/{$id}/approve", ['accept_overload' => true])->assertUnprocessable()->assertJsonValidationErrors('comment');
    $this->postJson("/api/v1/planning/scenarios/{$id}/approve", ['accept_overload' => true, 'comment' => 'Overtime approved'])->assertOk()->assertJsonPath('data.status', 'approved');

    $first = $this->postJson("/api/v1/planning/scenarios/{$id}/publish", ['comment' => 'Go'])->assertCreated()->json('data');
    expect($first['version'])->toBe(1)->and($first['is_current'])->toBeTrue()
        ->and($first['snapshot']['capacity']['overloaded_count'])->toBe(1)
        ->and($first['snapshot']['totals']['total_gap'])->toBe('-20.000');

    $this->postJson("/api/v1/planning/scenarios/{$id}/lock")->assertOk();
    $second = $this->postJson("/api/v1/planning/scenarios/{$id}/publish")->assertCreated()->json('data');
    expect($second['version'])->toBe(2);

    $list = $this->getJson('/api/v1/planning/publications')->assertOk()->json('data');
    expect(array_column($list, 'version'))->toBe([2, 1])->and(array_column($list, 'is_current'))->toBe([true, false]);
    $this->getJson("/api/v1/planning/scenarios/{$id}")->assertJsonPath('data.published_version', 2);
    $this->deleteJson("/api/v1/planning/scenarios/{$id}")->assertUnprocessable();
});

test('a what-if copy scales the lines, copies the constraints and starts a fresh cycle', function (): void {
    $baseline = plnScenario();
    $resource = plnResource();
    plnLine($baseline['id'], 'demand', 'P-1', '2028-01-01', '100');
    plnLine($baseline['id'], 'supply', 'P-1', '2028-01-01', '80');
    $this->postJson("/api/v1/planning/scenarios/{$baseline['id']}/constraints", ['resource_id' => $resource['id'], 'period' => '2028-01-01', 'available_capacity' => '50'])->assertCreated();
    $this->postJson("/api/v1/planning/scenarios/{$baseline['id']}/submit")->assertOk();
    $this->postJson("/api/v1/planning/scenarios/{$baseline['id']}/approve")->assertOk();
    $this->postJson("/api/v1/planning/scenarios/{$baseline['id']}/lock")->assertOk();

    $copy = $this->postJson("/api/v1/planning/scenarios/{$baseline['id']}/copy", ['name' => 'Demand +20%', 'demand_adjustment_percent' => 20, 'supply_adjustment_percent' => -10])
        ->assertCreated()->json('data');
    expect($copy['scenario_type'])->toBe('what_if')->and($copy['status'])->toBe('draft')->and($copy['base_scenario_id'])->toBe($baseline['id'])->and($copy['lines_count'])->toBe(2);

    $lines = collect($this->getJson("/api/v1/planning/scenarios/{$copy['id']}/lines")->json('data'));
    expect($lines->firstWhere('line_type', 'demand')['quantity'])->toBe('120.000')
        ->and($lines->firstWhere('line_type', 'supply')['quantity'])->toBe('72.000')
        ->and($lines->pluck('source')->unique()->all())->toBe(['copied']);
    $this->getJson("/api/v1/planning/scenarios/{$copy['id']}/constraints")->assertJsonCount(1, 'data');
    plnLine($copy['id'], 'demand', 'P-9', '2028-01-01', '1')->assertCreated();

    $this->postJson("/api/v1/planning/scenarios/{$baseline['id']}/copy", ['demand_adjustment_percent' => -150])->assertUnprocessable()->assertJsonValidationErrors(['name', 'demand_adjustment_percent']);

    $comparison = $this->getJson("/api/v1/planning/scenarios/compare?base_id={$baseline['id']}&other_id={$copy['id']}")->assertOk()->json('data');
    $p1 = collect($comparison['items'])->firstWhere('item_code', 'P-1');
    expect($comparison['delta']['total_demand'])->toBe('21.000')
        ->and($comparison['delta']['total_supply'])->toBe('-8.000')
        ->and($p1['delta']['total_demand'])->toBe('20.000')
        ->and($p1['delta']['total_gap'])->toBe('-28.000')
        ->and($p1['delta']['max_shortage'])->toBe('28.000')
        ->and(collect($comparison['items'])->pluck('item_code')->all())->toBe(['P-1', 'P-9']);

    $this->getJson("/api/v1/planning/scenarios/compare?base_id={$baseline['id']}&other_id={$baseline['id']}")->assertUnprocessable()->assertJsonValidationErrors('other_id');
});

test('forecasts of the forecast source become demand lines and existing lines are kept unless overwritten', function (): void {
    app()->bind(DemandForecastSource::class, fn () => new class implements DemandForecastSource
    {
        public function forecasts(string $tenantId, CarbonInterface $from, CarbonInterface $to): array
        {
            $rows = [
                ['item_id' => null, 'item_code' => 'F-1', 'item_name' => 'Forecast item', 'forecast_date' => '2028-01-05', 'quantity' => '10.000'],
                ['item_id' => null, 'item_code' => 'F-1', 'item_name' => 'Forecast item', 'forecast_date' => '2028-01-25', 'quantity' => '15.500'],
                ['item_id' => null, 'item_code' => 'F-1', 'item_name' => 'Forecast item', 'forecast_date' => '2028-02-10', 'quantity' => '7.000'],
                ['item_id' => null, 'item_code' => 'F-1', 'item_name' => 'Forecast item', 'forecast_date' => '2028-12-10', 'quantity' => '99.000'],
            ];

            return array_values(array_filter($rows, static fn (array $row): bool => $row['forecast_date'] >= $from->toDateString() && $row['forecast_date'] <= $to->toDateString()));
        }

        public function findItem(string $tenantId, string $itemId): ?array
        {
            return null;
        }
    });

    $scenario = plnScenario(['horizon_end' => '2028-03-31']);
    plnLine($scenario['id'], 'demand', 'F-1', '2028-02-01', '1');

    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/import-forecasts")->assertOk()->assertJsonPath('data', ['created' => 1, 'updated' => 0, 'skipped' => 1]);
    $lines = collect($this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/lines")->json('data'));
    expect($lines)->toHaveCount(2)->and($lines->firstWhere('period', '2028-01-01')['quantity'])->toBe('25.500')->and($lines->firstWhere('period', '2028-02-01')['quantity'])->toBe('1.000');

    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/import-forecasts", ['overwrite' => true])->assertOk()->assertJsonPath('data', ['created' => 0, 'updated' => 2, 'skipped' => 0]);
    expect(PlanningPlanLine::query()->where('scenario_id', $scenario['id'])->whereDate('period', '2028-02-01')->value('quantity'))->toBe('7.000');

    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/submit")->assertOk();
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/import-forecasts")->assertUnprocessable();
});

test('the dashboard shows the gaps, the capacity load and the approvals waiting', function (): void {
    $this->getJson('/api/v1/planning/dashboard')->assertOk()->assertJsonPath('data.scenarios.total', 0)->assertJsonPath('data.selected_scenario', null);

    $scenario = plnScenario(['horizon_end' => '2028-02-29']);
    $resource = plnResource(['default_capacity' => '20']);
    $this->postJson("/api/v1/planning/resources/{$resource['id']}/rates", ['item_code' => 'P-1', 'load_per_unit' => '1'])->assertCreated();
    plnLine($scenario['id'], 'demand', 'P-1', '2028-01-01', '100');
    plnLine($scenario['id'], 'supply', 'P-1', '2028-01-01', '30');
    plnLine($scenario['id'], 'demand', 'P-2', '2028-01-01', '5');
    plnLine($scenario['id'], 'supply', 'P-2', '2028-01-01', '50');
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/submit")->assertOk();

    $data = $this->getJson('/api/v1/planning/dashboard')->assertOk()->json('data');
    expect($data['scenarios']['by_status']['review'])->toBe(1)
        ->and($data['pending_approval'][0]['id'])->toBe($scenario['id'])
        ->and($data['selected_scenario']['id'])->toBe($scenario['id'])
        ->and($data['top_gaps'])->toHaveCount(1)
        ->and($data['top_gaps'][0]['item_code'])->toBe('P-1')
        ->and($data['top_gaps'][0]['max_shortage'])->toBe('70.000')
        ->and($data['capacity']['summary']['overloaded_count'])->toBe(1)
        ->and($data['capacity']['resources'][0]['peak_utilization_percent'])->toBe('150.0')
        ->and($data['current_publication'])->toBeNull();

    $this->getJson('/api/v1/planning/dashboard?scenario_id='.Str::uuid())->assertUnprocessable()->assertJsonValidationErrors('scenario_id');
});

test('planning data never crosses company boundaries', function (): void {
    $scenario = plnScenario();
    $resource = plnResource();
    $line = plnLine($scenario['id'], 'demand', 'P-1', '2028-01-01', '10')->json('data');

    $other = plnTenant('Altra');
    plnAct(plnMember($other, 'owner'), $other);

    $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}")->assertNotFound();
    $this->putJson("/api/v1/planning/scenarios/{$scenario['id']}", ['name' => 'Hijack'])->assertNotFound();
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/submit")->assertNotFound();
    $this->postJson("/api/v1/planning/scenarios/{$scenario['id']}/copy", ['name' => 'Steal'])->assertNotFound();
    $this->getJson("/api/v1/planning/scenarios/{$scenario['id']}/balance")->assertNotFound();
    $this->putJson("/api/v1/planning/lines/{$line['id']}", ['quantity' => '1'])->assertNotFound();
    $this->deleteJson("/api/v1/planning/resources/{$resource['id']}")->assertNotFound();
    $this->getJson('/api/v1/planning/scenarios')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/planning/resources')->assertOk()->assertJsonCount(0, 'data');

    $mine = plnScenario(['name' => 'Mine']);
    $this->postJson("/api/v1/planning/scenarios/{$mine['id']}/constraints", ['resource_id' => $resource['id'], 'period' => '2028-01-01', 'available_capacity' => '1'])
        ->assertUnprocessable()->assertJsonValidationErrors('resource_id');
    $this->getJson("/api/v1/planning/scenarios/compare?base_id={$mine['id']}&other_id={$scenario['id']}")->assertUnprocessable()->assertJsonValidationErrors('other_id');
    expect(PlanningScenario::query()->withoutGlobalScopes()->where('tenant_id', $other->id)->count())->toBe(1);
});

test('resource codes are unique per company and a resource can be edited and deleted', function (): void {
    $resource = plnResource(['code' => 'ASM-1']);
    $this->postJson('/api/v1/planning/resources', ['code' => 'ASM-1', 'name' => 'Dup', 'unit' => 'hours', 'default_capacity' => '1'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/planning/resources', ['code' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'unit', 'default_capacity']);

    $this->putJson("/api/v1/planning/resources/{$resource['id']}", ['default_capacity' => '250', 'is_active' => false])->assertOk()->assertJsonPath('data.default_capacity', '250.000')->assertJsonPath('data.is_active', false);

    $rate = $this->postJson("/api/v1/planning/resources/{$resource['id']}/rates", ['item_code' => 'P-1', 'load_per_unit' => '2'])->assertCreated()->json('data');
    $this->putJson("/api/v1/planning/rates/{$rate['id']}", ['load_per_unit' => '3'])->assertOk()->assertJsonPath('data.load_per_unit', '3.00000');
    $this->postJson("/api/v1/planning/resources/{$resource['id']}/rates", ['item_code' => 'P-2', 'load_per_unit' => '0'])->assertUnprocessable()->assertJsonValidationErrors('load_per_unit');
    $this->getJson("/api/v1/planning/resources/{$resource['id']}")->assertOk()->assertJsonCount(1, 'data.rates');
    $this->deleteJson("/api/v1/planning/rates/{$rate['id']}")->assertNoContent();

    $this->deleteJson("/api/v1/planning/resources/{$resource['id']}")->assertNoContent();
    expect(PlanningResource::query()->count())->toBe(0);
});

test('a draft scenario can be edited and deleted with its lines', function (): void {
    $scenario = plnScenario();
    plnLine($scenario['id'], 'demand', 'P-1', '2028-04-01', '10');

    $this->putJson("/api/v1/planning/scenarios/{$scenario['id']}", ['horizon_end' => '2028-02-29'])->assertUnprocessable()->assertJsonValidationErrors('horizon_end');
    $this->putJson("/api/v1/planning/scenarios/{$scenario['id']}", ['horizon_end' => '2028-06-30', 'name' => 'Longer'])->assertOk()->assertJsonPath('data.name', 'Longer');
    $this->putJson("/api/v1/planning/scenarios/{$scenario['id']}", ['horizon_start' => '2028-08-01'])->assertUnprocessable()->assertJsonValidationErrors('horizon_end');

    $this->deleteJson("/api/v1/planning/scenarios/{$scenario['id']}")->assertNoContent();
    expect(PlanningScenario::query()->count())->toBe(0)->and(PlanningPlanLine::query()->count())->toBe(0);
    plnScenario(['name' => 'New baseline']);
});
