<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Budgeting\Models\BudgetAuditEvent;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

require_once __DIR__.'/Support/ControlHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = ctlTenant();
    $this->owner = ctlMember($this->tenant, 'owner');
    ctlAct($this->owner, $this->tenant);
    $this->expense = ctlAccount($this->tenant);
    $this->other = ctlAccount($this->tenant);
    $this->center = ctlCostCenter($this->tenant);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function famVersion(array $overrides = []): array
{
    return test()->postJson('/api/v1/budgeting/versions', ['name' => 'Budget '.Str::random(5), 'fiscal_year' => 2028, 'version_type' => 'budget', 'currency' => 'EUR', ...$overrides])->assertCreated()->json('data');
}

test('an approved budget is revised through a new draft revision and never edited', function (): void {
    $original = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');

    $this->postJson("/api/v1/budgeting/versions/{$original->id}/revise", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $revised = $this->postJson("/api/v1/budgeting/versions/{$original->id}/revise", ['reason' => 'Mid-year price increase of the supplier'])->assertCreated()->json('data');

    expect($revised['version_type'])->toBe('revised')->and($revised['status'])->toBe('draft')->and($revised['revision_number'])->toBe(1)
        ->and($revised['base_version_id'])->toBe($original->id)->and($revised['revision_reason'])->toBe('Mid-year price increase of the supplier')
        ->and($revised['is_formal'])->toBeTrue()
        ->and(BudgetLine::query()->where('version_id', $revised['id'])->count())->toBe(12);

    // One revision at a time, and the original stays untouched.
    $this->postJson("/api/v1/budgeting/versions/{$original->id}/revise", ['reason' => 'Second try'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/budgeting/versions/{$original->id}/lines", ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id, 'period' => 1, 'amount' => '5'])->assertUnprocessable();
    $this->putJson("/api/v1/budgeting/versions/{$original->id}", ['name' => 'Renamed'])->assertUnprocessable();
    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/lines", ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id, 'period' => 1, 'amount' => '1500'])->assertCreated();
    expect((string) BudgetLine::query()->where('version_id', $original->id)->where('period', 1)->value('amount'))->toBe('1000.00');

    // A draft cannot be revised.
    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/revise", ['reason' => 'Revising a draft'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('a revised budget is approved through the workflow and then governs the spend, an approved budget is never reopened', function (): void {
    $original = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');
    $revised = $this->postJson("/api/v1/budgeting/versions/{$original->id}/revise", ['reason' => 'Plan change'])->assertCreated()->json('data');

    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/submit")->assertOk();
    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/reject", ['comment' => 'Changed my mind'])->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/budgeting/versions/{$original->id}/reject", ['comment' => 'Reopen please'])->assertUnprocessable()->assertJsonValidationErrors('status');

    expect(BudgetVersion::query()->find($original->id)->status)->toBe('approved');

    $second = $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/revise", ['reason' => 'Second revision'])->assertCreated()->json('data');
    expect($second['revision_number'])->toBe(2)->and($second['base_version_id'])->toBe($revised['id']);
});

test('a revised budget cannot be approved while its baseline is not approved', function (): void {
    $original = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');
    $revised = $this->postJson("/api/v1/budgeting/versions/{$original->id}/revise", ['reason' => 'Plan change'])->assertCreated()->json('data');
    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/submit")->assertOk();
    BudgetVersion::query()->whereKey($original->id)->update(['status' => 'draft']);

    $this->postJson("/api/v1/budgeting/versions/{$revised['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('a scenario scales every amount of any version and keeps its kind', function (): void {
    $original = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');

    $worst = $this->postJson("/api/v1/budgeting/versions/{$original->id}/scenario", ['name' => 'Worst case', 'scenario_kind' => 'worst_case', 'adjustment_percent' => 12.5])->assertCreated()->json('data');
    expect($worst['version_type'])->toBe('scenario')->and($worst['scenario_kind'])->toBe('worst_case')->and($worst['status'])->toBe('draft')->and($worst['base_version_id'])->toBe($original->id);
    expect(BudgetLine::query()->where('version_id', $worst['id'])->pluck('amount')->unique()->all())->toBe(['1125.00']);
    expect((string) BudgetLine::query()->where('version_id', $original->id)->where('period', 1)->value('amount'))->toBe('1000.00');

    $this->postJson("/api/v1/budgeting/versions/{$original->id}/scenario", ['name' => 'Best case', 'scenario_kind' => 'best_case', 'adjustment_percent' => -10])->assertCreated();
    $this->postJson("/api/v1/budgeting/versions/{$original->id}/scenario", ['name' => 'Odd', 'scenario_kind' => 'sideways'])->assertUnprocessable()->assertJsonValidationErrors('scenario_kind');
    $this->postJson("/api/v1/budgeting/versions/{$original->id}/scenario", ['name' => 'Gone', 'scenario_kind' => 'custom', 'adjustment_percent' => -150])->assertUnprocessable()->assertJsonValidationErrors('adjustment_percent');
    $this->postJson("/api/v1/budgeting/versions/{$original->id}/scenario", ['name' => 'Worst case', 'scenario_kind' => 'custom'])->assertUnprocessable()->assertJsonValidationErrors('name');

    $compare = $this->getJson("/api/v1/budgeting/versions/{$original->id}/compare?with={$worst['id']}")->assertOk()->json('data');
    expect($compare['totals']['base'] ?? $compare['totals']['budget'] ?? null)->not->toBeNull();
});

test('a scenario version type needs its kind', function (): void {
    $this->postJson('/api/v1/budgeting/versions', ['name' => 'No kind', 'fiscal_year' => 2028, 'version_type' => 'scenario', 'currency' => 'EUR'])->assertUnprocessable()->assertJsonValidationErrors('scenario_kind');
    $scenario = famVersion(['version_type' => 'scenario', 'scenario_kind' => 'custom', 'granularity' => 'quarterly']);
    expect($scenario['scenario_kind'])->toBe('custom')->and($scenario['granularity'])->toBe('quarterly');
});

test('an amount is transferred between two lines of a draft with the total unchanged and an audit entry', function (): void {
    $version = famVersion();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/lines", ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id, 'period' => 1, 'amount' => '1000'])->assertCreated();
    $from = ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id];
    $to = ['account_id' => $this->other->id, 'cost_center_id' => $this->center->id];

    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/transfer", ['from' => $from, 'to' => $to, 'period' => 1, 'amount' => '300.50', 'comment' => 'Moved to training'])->assertNoContent();

    $amounts = BudgetLine::query()->where('version_id', $version['id'])->pluck('amount', 'account_id');
    expect((string) $amounts[$this->expense->id])->toBe('699.50')->and((string) $amounts[$this->other->id])->toBe('300.50');

    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/transfer", ['from' => $from, 'to' => $to, 'period' => 1, 'amount' => '800'])->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/transfer", ['from' => $from, 'to' => $from, 'period' => 1, 'amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/transfer", ['from' => $from, 'to' => $to, 'period' => 2, 'amount' => '1'])->assertUnprocessable();

    $audit = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/audit")->assertOk()->json('data');
    expect($audit[0]['action'])->toBe('transfer')->and($audit[0]['comment'])->toBe('Moved to training')->and($audit[0]['payload']['amount'])->toBe('300.50');

    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/submit")->assertOk();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/transfer", ['from' => $to, 'to' => $from, 'period' => 1, 'amount' => '1'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('a rolling forecast rolls forward with the closed months at actual and the months ahead from the forecast', function (): void {
    Carbon::setTestNow('2028-06-10 09:00:00');
    $rolling = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00', 'rolling_forecast', 'Rolling 2028');
    BudgetLine::query()->where('version_id', $rolling->id)->where('period', 6)->update(['amount' => '6000.00']);
    BudgetLine::query()->where('version_id', $rolling->id)->where('period', 1)->update(['amount' => '1111.00']);
    ctlPost($this->tenant, $this->expense, '2028-02-15', '700.00', $this->center);
    ctlPost($this->tenant, $this->expense, '2028-04-20', '250.00', $this->center);

    $rolled = $this->postJson("/api/v1/budgeting/versions/{$rolling->id}/roll-forward", ['shift_months' => 1, 'name' => 'Rolling 2028-02'])->assertCreated()->json('data');

    expect($rolled['year_start'])->toBe('2028-02-01')->and($rolled['version_type'])->toBe('rolling_forecast')->and($rolled['status'])->toBe('draft')
        ->and($rolled['reforecast_from_period'])->toBe(5)->and($rolled['rolling_horizon_months'])->toBe(12)->and($rolled['base_version_id'])->toBe($rolling->id);

    $lines = BudgetLine::query()->where('version_id', $rolled['id'])->get()->keyBy('period');
    expect($lines)->toHaveCount(12)
        ->and((string) $lines[1]->amount)->toBe('700.00')->and($lines[1]->source)->toBe('actual')
        ->and((string) $lines[2]->amount)->toBe('0.00')->and($lines[2]->source)->toBe('actual')
        ->and((string) $lines[3]->amount)->toBe('250.00')
        ->and((string) $lines[5]->amount)->toBe('6000.00')->and($lines[5]->source)->toBe('reforecast')
        ->and((string) $lines[12]->amount)->toBe('1111.00');

    $this->postJson("/api/v1/budgeting/versions/{$rolling->id}/roll-forward", ['name' => 'Rolling 2028-02'])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->postJson("/api/v1/budgeting/versions/{$rolled['id']}/roll-forward", [])->assertUnprocessable()->assertJsonValidationErrors('status');
    expect(BudgetAuditEvent::query()->where('action', 'roll_forward')->count())->toBe(1);
});

test('only a rolling forecast rolls forward', function (): void {
    $original = ctlApprovedBudget($this->tenant, $this->expense, $this->center, '1000.00');

    $this->postJson("/api/v1/budgeting/versions/{$original->id}/roll-forward", [])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('the monthly grid saves cells with quantity and unit price and reads them back with totals', function (): void {
    $version = famVersion();
    $cells = [
        ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id, 'slot' => 1, 'amount' => '1000', 'quantity' => '10', 'unit_price' => '100'],
        ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id, 'slot' => 2, 'amount' => '500'],
        ['account_id' => $this->other->id, 'slot' => 1, 'amount' => '200.25'],
    ];

    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => $cells])->assertOk()->assertJsonPath('data.saved', 3);
    $grid = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/grid")->assertOk()->json('data');

    expect($grid['granularity'])->toBe('monthly')->and($grid['slots'])->toHaveCount(12)->and($grid['rows'])->toHaveCount(2)
        ->and($grid['totals']['total'])->toBe('1700.25')->and($grid['totals']['slots']['1'])->toBe('1200.25');
    $first = collect($grid['rows'])->firstWhere('account_id', $this->expense->id);
    expect($first['cells']['1']['amount'])->toBe('1000.00')->and($first['cells']['1']['quantity'])->toBe('10.000')->and($first['cells']['1']['unit_price'])->toBe('100.0000')
        ->and($first['total'])->toBe('1500.00')->and($first['cost_center_code'])->toBe($this->center->code);

    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [['account_id' => $this->expense->id, 'slot' => 13, 'amount' => '1']]])->assertUnprocessable();
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [['account_id' => (string) Str::uuid(), 'slot' => 1, 'amount' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('account_id');
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => []])->assertUnprocessable()->assertJsonValidationErrors('cells');

    $audit = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/audit")->assertOk()->json('data');
    expect($audit[0]['action'])->toBe('grid_saved')->and($audit[0]['payload']['cells'])->toBe(3);
});

test('a quarterly grid spreads each quarter over its months and the cents go to the last month', function (): void {
    $version = famVersion(['granularity' => 'quarterly']);
    $cell = ['account_id' => $this->expense->id, 'cost_center_id' => $this->center->id, 'slot' => 2, 'amount' => '1000', 'quantity' => '10'];

    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [$cell]])->assertOk();

    $lines = BudgetLine::query()->where('version_id', $version['id'])->orderBy('period')->get();
    expect($lines->pluck('period')->all())->toBe([4, 5, 6])
        ->and($lines->map(fn (BudgetLine $line): string => (string) $line->amount)->all())->toBe(['333.33', '333.33', '333.34'])
        ->and($lines->map(fn (BudgetLine $line): string => (string) $line->quantity)->all())->toBe(['3.333', '3.333', '3.334']);

    $grid = $this->getJson("/api/v1/budgeting/versions/{$version['id']}/grid")->assertOk()->json('data');
    expect($grid['slots'])->toHaveCount(4)->and($grid['slots'][1])->toMatchArray(['slot' => 2, 'label' => 'Q2', 'from_period' => 4, 'to_period' => 6])
        ->and($grid['rows'][0]['cells']['2']['amount'])->toBe('1000.00')->and($grid['rows'][0]['cells']['2']['quantity'])->toBe('10.000');

    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [[...$cell, 'slot' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('cells');
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [[...$cell, 'slot' => 2, 'amount' => '10']]])->assertOk();
    expect(BudgetLine::query()->where('version_id', $version['id'])->count())->toBe(3);
});

test('the grid of a version that left the draft cannot be saved and read-only roles never write it', function (): void {
    $version = famVersion();
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [['account_id' => $this->expense->id, 'slot' => 1, 'amount' => '10']]])->assertOk();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/submit")->assertOk();
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [['account_id' => $this->expense->id, 'slot' => 1, 'amount' => '20']]])->assertUnprocessable()->assertJsonValidationErrors('status');

    $viewer = ctlMember($this->tenant, 'accountant_readonly');
    ctlAct($viewer, $this->tenant);
    $this->getJson("/api/v1/budgeting/versions/{$version['id']}/grid")->assertOk();
    $this->putJson("/api/v1/budgeting/versions/{$version['id']}/grid", ['cells' => [['account_id' => $this->expense->id, 'slot' => 1, 'amount' => '20']]])->assertForbidden();
    $this->postJson("/api/v1/budgeting/versions/{$version['id']}/revise", ['reason' => 'No way'])->assertForbidden();
});
