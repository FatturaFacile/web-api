<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetActualVolume;

require_once __DIR__.'/Support/ControlHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = ctlTenant();
    $this->owner = ctlMember($this->tenant, 'owner');
    ctlAct($this->owner, $this->tenant);
    $this->materials = ctlAccount($this->tenant);
    $this->services = ctlAccount($this->tenant);
    $this->center = ctlCostCenter($this->tenant);
    $this->otherCenter = ctlCostCenter($this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * A draft budget with a quantity and a unit price on the materials of the first month and an amount only on the services.
 *
 * @return array<string, mixed>
 */
function varBudget(): array
{
    $version = test()->postJson('/api/v1/budgeting/versions', ['name' => 'Budget '.Str::random(5), 'fiscal_year' => 2028, 'version_type' => 'budget', 'currency' => 'EUR'])->assertCreated()->json('data');
    test()->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [
        ['account_id' => test()->materials->id, 'cost_center_id' => test()->center->id, 'slot' => 1, 'amount' => '1000', 'quantity' => '10', 'unit_price' => '100'],
        ['account_id' => test()->services->id, 'cost_center_id' => test()->center->id, 'slot' => 1, 'amount' => '2000'],
        ['account_id' => test()->services->id, 'cost_center_id' => test()->otherCenter->id, 'slot' => 2, 'amount' => '500'],
    ]])->assertOk();
    test()->postJson("/api/v1/budgeting/versions/{$version['id']}/submit")->assertOk();
    test()->postJson("/api/v1/budgeting/versions/{$version['id']}/approve")->assertOk();

    return $version;
}

test('the variance carries the traffic light and the thresholds of the company', function (): void {
    $version = ctlApprovedBudget($this->tenant, $this->materials, $this->center, '1000.00');
    ctlPost($this->tenant, $this->materials, '2028-02-10', '3150.00', $this->center);

    $report = $this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?through_period=3")->assertOk()->json('data');
    expect($report['thresholds'])->toBe(['amber' => '5.00', 'red' => '10.00'])->and($report['rows'][0]['variance'])->toBe('150.00')->and($report['rows'][0]['variance_percent'])->toBe('5.0')->and($report['rows'][0]['traffic_light'])->toBe('green');

    ctlPost($this->tenant, $this->materials, '2028-03-10', '150.00', $this->center);
    expect($this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?through_period=3")->json('data.rows.0.traffic_light'))->toBe('amber');

    ctlPost($this->tenant, $this->materials, '2028-03-11', '300.00', $this->center);
    expect($this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?through_period=3")->json('data.rows.0.traffic_light'))->toBe('red');

    $this->putJson('/api/v1/budgeting/spend-control/settings', ['amber_threshold_percent' => 20, 'red_threshold_percent' => 30])->assertOk();
    expect($this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?through_period=3")->json('data.rows.0.traffic_light'))->toBe('green');
});

test('under budget is green and the variance can be filtered by cost centre', function (): void {
    $version = ctlApprovedBudget($this->tenant, $this->materials, $this->center, '1000.00');
    ctlPost($this->tenant, $this->materials, '2028-01-10', '500.00', $this->center);
    ctlPost($this->tenant, $this->materials, '2028-01-12', '9000.00', $this->otherCenter);

    $filtered = $this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?through_period=1&cost_center_id={$this->center->id}")->assertOk()->json('data');
    expect($filtered['totals']['actual'])->toBe('500.00')->and($filtered['totals']['budget'])->toBe('1000.00')->and($filtered['rows'][0]['traffic_light'])->toBe('green')->and($filtered['rows'][0]['is_favorable'])->toBeTrue();

    $unfiltered = $this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?through_period=1")->json('data');
    expect($unfiltered['totals']['actual'])->toBe('9500.00');
    $this->getJson("/api/v1/budgeting/versions/{$version->id}/variance?cost_center_id=not-a-uuid")->assertUnprocessable()->assertJsonValidationErrors('cost_center_id');
});

test('the variance is split into a volume and a price effect where both quantities exist', function (): void {
    $version = varBudget();
    ctlPost($this->tenant, $this->materials, '2028-01-15', '1380.00', $this->center);
    ctlPost($this->tenant, $this->services, '2028-01-20', '2100.00', $this->center);
    $this->postJson('/api/v1/budgeting/actual-volumes', ['account_id' => $this->materials->id, 'cost_center_id' => $this->center->id, 'month' => '2028-01', 'quantity' => '12'])->assertCreated();

    $report = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/variance/decomposition?through_period=1")->assertOk()->json('data');
    $rows = collect($report['rows'])->keyBy('label');
    $materials = $rows->first(fn (array $row): bool => $row['decomposable']);
    $services = $rows->first(fn (array $row): bool => ! $row['decomposable']);

    expect($materials)->toMatchArray(['budget' => '1000.00', 'actual' => '1380.00', 'variance' => '380.00', 'budget_quantity' => '10.000', 'actual_quantity' => '12.000', 'budget_price' => '100.0000', 'actual_price' => '115.0000', 'volume_variance' => '200.00', 'price_variance' => '180.00'])
        ->and($services)->toMatchArray(['budget' => '2000.00', 'actual' => '2100.00', 'variance' => '100.00', 'volume_variance' => null, 'price_variance' => null, 'budget_quantity' => null])
        ->and($report['totals'])->toMatchArray(['budget' => '3000.00', 'actual' => '3480.00', 'variance' => '480.00', 'volume_variance' => '200.00', 'price_variance' => '180.00', 'undecomposed_variance' => '100.00'])
        ->and(bcadd($report['totals']['volume_variance'], bcadd($report['totals']['price_variance'], $report['totals']['undecomposed_variance'], 2), 2))->toBe($report['totals']['variance']);
});

test('the decomposition can be grouped by cost centre and filtered', function (): void {
    $version = varBudget();
    ctlPost($this->tenant, $this->materials, '2028-01-15', '1380.00', $this->center);
    BudgetActualVolume::create(['tenant_id' => $this->tenant->id, 'account_id' => $this->materials->id, 'cost_center_id' => $this->center->id, 'month' => '2028-01', 'quantity' => '12.000']);

    $byCenter = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/variance/decomposition?through_period=2&group_by=cost_center")->assertOk()->json('data');
    $rows = collect($byCenter['rows'])->keyBy('code');
    expect($byCenter['rows'])->toHaveCount(2)
        ->and($rows[$this->center->code])->toMatchArray(['budget' => '3000.00', 'actual' => '1380.00', 'variance' => '-1620.00', 'volume_variance' => '200.00', 'price_variance' => '180.00', 'undecomposed_variance' => '-2000.00', 'decomposable' => true, 'budget_quantity' => null])
        ->and($rows[$this->otherCenter->code])->toMatchArray(['budget' => '500.00', 'undecomposed_variance' => '-500.00', 'decomposable' => false, 'volume_variance' => null]);

    $one = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/variance/decomposition?through_period=1&account_id={$this->materials->id}")->assertOk()->json('data');
    expect($one['rows'])->toHaveCount(1)->and($one['rows'][0]['volume_variance'])->toBe('200.00');
    $this->getJson("/api/v1/budgeting/versions/{$version['id']}/variance/decomposition?group_by=period")->assertUnprocessable()->assertJsonValidationErrors('group_by');
});

test('actual volumes are saved once per account, cost centre and month and validated', function (): void {
    $payload = ['account_id' => $this->materials->id, 'cost_center_id' => $this->center->id, 'month' => '2028-01', 'quantity' => '12.5', 'notes' => 'Counted'];

    $first = $this->postJson('/api/v1/budgeting/actual-volumes', $payload)->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/budgeting/actual-volumes', [...$payload, 'quantity' => '13'])->assertCreated()->json('data');
    expect($second['id'])->toBe($first['id'])->and($second['quantity'])->toBe('13.000')->and(BudgetActualVolume::query()->count())->toBe(1);

    $this->postJson('/api/v1/budgeting/actual-volumes', [...$payload, 'cost_center_id' => null])->assertCreated();
    expect(BudgetActualVolume::query()->count())->toBe(2);

    $this->postJson('/api/v1/budgeting/actual-volumes', [...$payload, 'month' => '2028-13'])->assertUnprocessable()->assertJsonValidationErrors('month');
    $this->postJson('/api/v1/budgeting/actual-volumes', [...$payload, 'quantity' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->postJson('/api/v1/budgeting/actual-volumes', [...$payload, 'account_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->getJson("/api/v1/budgeting/actual-volumes?month=2028-01&cost_center_id={$this->center->id}")->assertOk()->assertJsonCount(1, 'data');

    $this->deleteJson("/api/v1/budgeting/actual-volumes/{$first['id']}")->assertNoContent();
    expect(BudgetActualVolume::query()->count())->toBe(1);
});

test('only authorised roles record actual volumes and other companies never see them', function (): void {
    $payload = ['account_id' => $this->materials->id, 'month' => '2028-01', 'quantity' => '5'];
    $volume = $this->postJson('/api/v1/budgeting/actual-volumes', $payload)->assertCreated()->json('data');

    ctlAct(ctlMember($this->tenant, 'member'), $this->tenant);
    $this->postJson('/api/v1/budgeting/actual-volumes', $payload)->assertForbidden();
    $this->deleteJson("/api/v1/budgeting/actual-volumes/{$volume['id']}")->assertForbidden();
    $this->getJson('/api/v1/budgeting/actual-volumes')->assertOk()->assertJsonCount(1, 'data');

    $foreign = ctlTenant('Foreign');
    ctlAct(ctlMember($foreign, 'owner'), $foreign);
    $this->getJson('/api/v1/budgeting/actual-volumes')->assertOk()->assertJsonCount(0, 'data');
    $this->deleteJson("/api/v1/budgeting/actual-volumes/{$volume['id']}")->assertNotFound();
});
