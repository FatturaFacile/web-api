<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ControllingDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Controlling\Models\BudgetLine;
use Modules\Controlling\Support\Decimal;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Ctl '.Str::random(5), 'legal_name' => 'Ctl Srl', 'country' => 'CH', 'default_currency' => 'EUR']);
    $this->ids = (new ControllingDemoSeeder)->run($this->tenant);
    $this->owner = cpmMember($this->tenant, 'owner');
    cpmAct($this->owner, $this->tenant);
    $this->year = ControllingDemoSeeder::Year;
});

afterEach(fn () => app(TenantContext::class)->clear());

function cpmMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@controlling-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function cpmAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

function cpmEntrySum(string $column, string $value): string
{
    return Decimal::norm(number_format((float) DB::table('analytical_ledger_entries')->where($column, $value)->sum('amount'), 4, '.', ''));
}

test('allocation preview, execution and reversal balance to zero and restore the pools', function (): void {
    $payload = ['fiscal_year' => $this->year, 'fiscal_month' => 3];
    $preview = $this->postJson('/api/v1/controlling/allocations/preview', $payload)->assertOk()->json('data');
    expect($preview['transfers'])->not->toBeEmpty();
    $sum = array_reduce($preview['transfers'], static fn (string $c, array $t): string => Decimal::add($c, $t['amount']), '0.0000');
    expect($sum)->toBe(Decimal::norm($preview['total_allocated']));
    expect(DB::table('analytical_ledger_entries')->where('source_type', 'overhead_allocation')->count())->toBe(0);

    $run = $this->postJson('/api/v1/controlling/allocations/execute', $payload)->assertCreated()->json('data');
    expect(cpmEntrySum('allocation_run_id', $run['id']))->toBe('0.0000');
    foreach (['CC-100', 'CC-200'] as $code) {
        $id = (string) DB::table('analytical_cost_centers')->where('code', $code)->value('id');
        $net = Decimal::norm(number_format((float) DB::table('analytical_ledger_entries')->where('cost_center_id', $id)->where('fiscal_month', 3)->where('fiscal_year', $this->year)->sum('amount'), 4, '.', ''));
        expect($net)->toBe('0.0000');
    }
    $this->postJson('/api/v1/controlling/allocations/execute', $payload)->assertUnprocessable();

    $this->postJson("/api/v1/controlling/allocations/{$run['id']}/reverse")->assertOk()->assertJsonPath('data.status', 'reversed');
    expect(cpmEntrySum('allocation_run_id', $run['id']))->toBe('0.0000');
    $cc100 = (string) DB::table('analytical_cost_centers')->where('code', 'CC-100')->value('id');
    expect(Decimal::cmp(cpmEntrySum('cost_center_id', $cc100), 0))->toBeGreaterThan(0);
    $this->postJson("/api/v1/controlling/allocations/{$run['id']}/reverse")->assertUnprocessable();
    $this->postJson('/api/v1/controlling/allocations/execute', $payload)->assertCreated();
});

test('margin tiers, credit note deduction and totals reconcile across every dimension', function (): void {
    $this->postJson('/api/v1/controlling/allocations/execute', ['fiscal_year' => $this->year, 'fiscal_month' => 3])->assertCreated();
    $query = ['date_from' => "{$this->year}-01-01", 'date_to' => "{$this->year}-12-31"];
    $t = $this->getJson('/api/v1/analytics/margins/summary?'.http_build_query($query))->assertOk()->json('data.totals');

    expect(Decimal::sub(Decimal::sub($t['gross_revenue'], $t['discounts']), $t['credit_notes']))->toBe($t['net_revenue']);
    expect(Decimal::cmp($t['credit_notes'], 0))->toBeGreaterThan(0);
    expect(Decimal::cmp($t['gross_margin'], $t['commercial_margin']))->toBeGreaterThanOrEqual(0);
    expect(Decimal::cmp($t['commercial_margin'], $t['full_industrial_margin']))->toBeGreaterThanOrEqual(0);
    expect(Decimal::cmp($t['allocated_overheads'], 0))->toBeGreaterThan(0);

    foreach (['product', 'product_family', 'customer', 'geography', 'agent', 'production_department', 'cost_center', 'job'] as $dimension) {
        $rows = $this->getJson('/api/v1/analytics/margins/explore?'.http_build_query($query + ['group_by' => $dimension, 'per_page' => 500]))->assertOk()->json('data');
        foreach (['net_revenue', 'gross_margin', 'full_industrial_margin', 'allocated_overheads'] as $metric) {
            $sum = array_reduce($rows, static fn (string $c, array $r): string => Decimal::add($c, $r['metrics'][$metric]), '0.0000');
            expect($sum)->toBe($t[$metric], "{$dimension} {$metric}");
        }
    }
});

test('the explorer drills down geography, compares to the prior year and classifies ABC', function (): void {
    $query = ['date_from' => "{$this->year}-01-01", 'date_to' => "{$this->year}-08-31", 'group_by' => 'geography', 'compare_mode' => 'prior_year'];
    $countries = $this->getJson('/api/v1/analytics/margins/explore?'.http_build_query($query))->assertOk()->json();
    expect(collect($countries['data'])->pluck('key')->sort()->values()->all())->toBe(['CH', 'IT']);
    expect($countries['data'][0]['baseline'])->not->toBeNull();

    $regions = $this->getJson('/api/v1/analytics/margins/explore?'.http_build_query(['drill_path' => [['dimension' => 'geography', 'value' => 'CH']]] + $query))->assertOk()->json();
    expect(collect($regions['data'])->pluck('key')->sort()->values()->all())->toBe(['GE', 'TI', 'ZH']);

    $pareto = $this->getJson('/api/v1/analytics/margins/pareto?'.http_build_query(['date_from' => "{$this->year}-01-01", 'date_to' => "{$this->year}-08-31", 'group_by' => 'product']))->assertOk()->json('data');
    expect($pareto['curve'][0]['abc_class'])->toBe('A');
    expect(end($pareto['curve'])['cumulative_share'])->toBe('100.00');
    // The loss maker (SKU-011) erodes the margin.
    expect(collect($pareto['outliers'])->contains(fn (array $o): bool => str_contains($o['label'], 'Loss Maker')))->toBeTrue();

    $matrix = $this->getJson('/api/v1/analytics/margins/matrix?'.http_build_query(['date_from' => "{$this->year}-01-01", 'date_to' => "{$this->year}-08-31", 'row_dimension' => 'product_family', 'col_dimension' => 'geography']))->assertOk()->json('data');
    expect($matrix['rows'])->toHaveCount(4)->and($matrix['columns'])->toHaveCount(2);
});

test('the margin export streams csv', function (): void {
    $response = $this->get('/api/v1/analytics/margins/export?'.http_build_query(['date_from' => "{$this->year}-01-01", 'date_to' => "{$this->year}-08-31", 'group_by' => 'customer', 'format' => 'csv']));
    $response->assertOk();
    expect($response->streamedContent())->toContain('Net revenue')->toContain('Alpina SA');
});

test('financial KPIs satisfy the working capital identities and are safe on empty denominators', function (): void {
    $data = $this->getJson('/api/v1/analytics/financial-kpis?as_of='.$this->year.'-12-31')->assertOk()->json('data');
    $c = $data['current'];
    expect(Decimal::sub(Decimal::add($c['inventory'], $c['accounts_receivable']), $c['accounts_payable']))->toBe($c['ccn']);
    expect(Decimal::round(Decimal::sub(Decimal::add($c['dio'], $c['dso']), $c['dpo']), 2))->toBe($c['ccc']);
    expect($data['history'])->toHaveCount(12);
    expect(Decimal::cmp($c['dso'], 0))->toBeGreaterThan(0);

    $this->postJson('/api/v1/analytics/financial-kpis/snapshots', ['snapshot_date' => '2030-01-31', 'mode' => 'manual', 'accounts_receivable_balance' => 0, 'accounts_payable_balance' => 0, 'inventory_valuation_balance' => 0])->assertCreated();
    $zero = $this->getJson('/api/v1/analytics/financial-kpis?as_of=2030-01-31&months=1')->assertOk()->json('data.current');
    expect($zero['dso'])->toBe('0.00')->and($zero['ccc'])->toBe('0.00')->and($zero['warnings'])->toContain('dso_undefined');
});

test('budgets: seed from prior year, workflow, bulk edits, rolling forecast and the bridge reconcile', function (): void {
    $plan = $this->postJson('/api/v1/budgets/seed-from-prior-year', ['code' => 'BUD-V1', 'name' => 'Budget v1', 'fiscal_year' => $this->year, 'uplift_percent' => 5])->assertCreated()->json('data');
    expect($plan['lines_count'])->toBeGreaterThan(0);
    $detail = $this->getJson("/api/v1/budgets/{$plan['id']}")->assertOk()->json('data');
    foreach ($detail['lines'] as $line) {
        $sum = array_reduce(BudgetLine::monthColumns(), static fn (string $c, string $col): string => Decimal::add($c, $line[$col]), '0.0000');
        expect($sum)->toBe(Decimal::norm($line['annual_total']));
    }

    // Draft only: bulk edit with seasonality redistribution, then the workflow.
    $line = $detail['lines'][0];
    $this->putJson("/api/v1/budgets/{$plan['id']}/lines/bulk", ['lines' => [['id' => $line['id'], 'annual_total' => 120000.01, 'distribution_driver' => 'prior_year_seasonality']]])->assertOk();
    $edited = collect($this->getJson("/api/v1/budgets/{$plan['id']}")->json('data.lines'))->firstWhere('id', $line['id']);
    expect(array_reduce(BudgetLine::monthColumns(), static fn (string $c, string $col): string => Decimal::add($c, $edited[$col]), '0.0000'))->toBe('120000.0100');

    $this->postJson("/api/v1/budgets/{$plan['id']}/lock")->assertUnprocessable();
    $this->postJson("/api/v1/budgets/{$plan['id']}/submit")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->putJson("/api/v1/budgets/{$plan['id']}/lines/bulk", ['lines' => [['id' => $line['id'], 'annual_total' => 1]]])->assertUnprocessable();
    $this->postJson("/api/v1/budgets/{$plan['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

    $forecast = $this->postJson("/api/v1/budgets/{$plan['id']}/generate-rolling-forecast", ['cutoff_month' => 8])->assertCreated()->json('data');
    expect($forecast['plan_type'])->toBe('rolling_forecast')->and($forecast['cutoff_month'])->toBe(8);
    $closedMonth = collect($forecast['lines'])->firstWhere('nature_type', 'revenue');
    expect(Decimal::cmp($closedMonth['month_01'], 0))->toBeGreaterThan(0);
    $this->putJson("/api/v1/budgets/{$forecast['id']}/lines/bulk", ['lines' => [['id' => $closedMonth['id'], 'month_01' => 1]]])->assertUnprocessable();

    foreach (['ytd', 'monthly', 'full_year_eac'] as $mode) {
        $steps = collect($this->getJson('/api/v1/analytics/variance/waterfall?'.http_build_query(['budget_id' => $plan['id'], 'forecast_id' => $forecast['id'], 'period_mode' => $mode, 'month' => 8]))->assertOk()->json('data.steps'));
        $walk = $steps->where('type', 'delta')->reduce(static fn (string $c, array $s): string => Decimal::add($c, $s['value']), $steps->firstWhere('key', 'budget_margin')['value']);
        expect($walk)->toBe($steps->firstWhere('key', 'actual_margin')['value'], $mode);
    }

    foreach (['nature', 'cost_center', 'job', 'product_family', 'customer', 'agent', 'department', 'geography'] as $groupBy) {
        $variance = $this->getJson('/api/v1/analytics/variance?'.http_build_query(['budget_id' => $plan['id'], 'forecast_id' => $forecast['id'], 'group_by' => $groupBy, 'period_mode' => 'ytd', 'month' => 8]))->assertOk()->json();
        $variance['data'] !== [] || throw new RuntimeException("{$groupBy} variance is empty");
        $rowSum = array_reduce($variance['data'], static fn (string $c, array $r): string => Decimal::add($c, $r['actual']['margin']), '0.0000');
        expect($rowSum)->toBe($variance['totals']['actual_margin']);
    }
});

test('a clone applies the uplift to every line', function (): void {
    $plan = $this->postJson('/api/v1/budgets/seed-from-prior-year', ['code' => 'BUD-A', 'name' => 'A', 'fiscal_year' => $this->year])->assertCreated()->json('data');
    $clone = $this->postJson("/api/v1/budgets/{$plan['id']}/clone", ['code' => 'BUD-B', 'name' => 'B +10%', 'uplift_percent' => 10, 'plan_type' => 'what_if_scenario'])->assertCreated()->json('data');
    $a = DB::table('controlling_budget_lines')->where('plan_id', $plan['id'])->sum('annual_total');
    $b = DB::table('controlling_budget_lines')->where('plan_id', $clone['id'])->sum('annual_total');
    expect(round($b / $a, 3))->toBe(1.1)->and($clone['plan_type'])->toBe('what_if_scenario');
});

test('cost centers, jobs and the analytical ledger: tree rollup, job P&L and manual entries', function (): void {
    $tree = $this->getJson("/api/v1/controlling/cost-centers?view=tree&fiscal_year={$this->year}")->assertOk()->json('data');
    $plant = collect($tree)->firstWhere('code', 'CC-300');
    expect(collect($plant['children'])->pluck('code')->all())->toBe(['CC-310', 'CC-320']);
    $children = array_reduce($plant['children'], static fn (string $c, array $n): string => Decimal::add($c, $n['rollup']['revenue']), '0.0000');
    expect($plant['rollup']['revenue'])->toBe(Decimal::add($plant['own']['revenue'], $children));

    $job = $this->getJson('/api/v1/controlling/jobs')->assertOk()->json('data.0');
    $pnl = $this->getJson("/api/v1/controlling/jobs/{$job['id']}/pnl")->assertOk()->json('data');
    expect($pnl['actual_margin'])->toBe(Decimal::sub($pnl['invoiced_to_date'], $pnl['actual_cost']));

    $centerId = $this->ids['centers']['CC-400'];
    $this->postJson('/api/v1/controlling/analytical-ledger', ['posting_date' => "{$this->year}-02-10", 'nature_type' => 'opex', 'cost_center_id' => $centerId, 'amount' => -150.5, 'description' => 'Adjustment'])
        ->assertCreated()->assertJsonPath('data.source_type', 'manual_analytical');
    $this->getJson("/api/v1/controlling/analytical-ledger?cost_center_id={$centerId}&source_type=manual_analytical")->assertOk()->assertJsonFragment(['description' => 'Adjustment']);

    $created = $this->postJson('/api/v1/controlling/cost-centers', ['code' => 'CC-900', 'name' => 'New', 'center_type' => 'auxiliary_service', 'parent_id' => $centerId])->assertCreated()->json('data');
    $this->postJson('/api/v1/controlling/cost-centers', ['code' => 'CC-900', 'name' => 'Dup', 'center_type' => 'production'])->assertUnprocessable();
    $this->deleteJson("/api/v1/controlling/cost-centers/{$centerId}")->assertUnprocessable();
    $this->deleteJson("/api/v1/controlling/cost-centers/{$created['id']}")->assertNoContent();
});

test('another company sees none of the controlling data and cannot reference it', function (): void {
    $other = Tenant::create(['name' => 'Other '.Str::random(5), 'legal_name' => 'Other Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    cpmAct(cpmMember($other, 'owner'), $other);

    $this->getJson('/api/v1/controlling/cost-centers')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/analytics/margins/summary')->assertOk()->assertJsonPath('data.totals.net_revenue', '0.0000');
    $this->getJson('/api/v1/controlling/cost-centers/'.$this->ids['centers']['CC-100'])->assertNotFound();
    $this->postJson('/api/v1/controlling/jobs', ['code' => 'X', 'name' => 'X', 'status' => 'planned', 'cost_center_id' => $this->ids['centers']['CC-100']])->assertUnprocessable();
});

test('roles: a reader cannot write, only approvers approve and only finance runs allocations', function (): void {
    $reader = cpmMember($this->tenant, 'accountant_readonly');
    cpmAct($reader, $this->tenant);
    $this->getJson('/api/v1/controlling/cost-centers')->assertOk();
    $this->postJson('/api/v1/controlling/cost-centers', ['code' => 'CC-901', 'name' => 'Nope', 'center_type' => 'production'])->assertForbidden();

    $accountant = cpmMember($this->tenant, 'accountant');
    cpmAct($accountant, $this->tenant);
    $this->postJson('/api/v1/controlling/allocations/execute', ['fiscal_year' => $this->year, 'fiscal_month' => 4])->assertCreated();
    $plan = $this->postJson('/api/v1/budgets', ['code' => 'BUD-R', 'name' => 'Roles', 'fiscal_year' => $this->year])->assertCreated()->json('data');
    $this->postJson("/api/v1/budgets/{$plan['id']}/submit")->assertOk();
    $this->postJson("/api/v1/budgets/{$plan['id']}/approve")->assertForbidden();

    cpmAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/budgets/{$plan['id']}/approve")->assertOk();
});

test('the verification command passes on the demo dataset', function (): void {
    $this->artisan('controlling:verify-integrity', ['--tenant' => $this->tenant->id])->assertSuccessful();
});

test('rebuilding the facts from posted invoices deducts credit notes and skips drafts', function (): void {
    $tenantId = (string) $this->tenant->id;
    $customer = $this->ids['customers'][0];
    $product = $this->ids['products'][0];
    $mk = function (string $type, string $status, mixed $postedAt) use ($tenantId, $customer, $product): void {
        $id = (string) Str::uuid7();
        DB::table('invoices')->insert(['id' => $id, 'tenant_id' => $tenantId, 'contact_id' => $customer, 'invoice_type' => $type, 'invoice_number' => 'T-'.Str::random(6), 'date' => '2024-05-10', 'currency' => 'EUR', 'exchange_rate' => 1, 'status' => $status, 'posted_at' => $postedAt, 'total_net' => 1000, 'total_gross' => 1220, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoice_items')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'invoice_id' => $id, 'product_id' => $product, 'description' => 'Item', 'quantity' => 10, 'unit_price' => 100, 'discount_percentage' => 10, 'line_total' => 900, 'created_at' => now(), 'updated_at' => now()]);
    };
    $mk('standard', 'issued', now());
    $mk('credit_note', 'issued', now());
    $mk('standard', 'draft', null);

    $this->artisan('bi:rebuild-facts', ['--tenant' => $tenantId, '--from' => '2024-05-01', '--to' => '2024-05-31'])->assertSuccessful();
    $t = $this->getJson('/api/v1/analytics/margins/summary?date_from=2024-05-01&date_to=2024-05-31')->assertOk()->json('data.totals');
    expect($t['gross_revenue'])->toBe('1000.0000')->and($t['discounts'])->toBe('100.0000')->and($t['credit_notes'])->toBe('900.0000')->and($t['net_revenue'])->toBe('0.0000');
    // The seeded facts of other dates are untouched.
    expect(DB::table('bi_sales_margin_facts')->where('tenant_id', $tenantId)->where('posting_date', '>=', "{$this->year}-01-01")->exists())->toBeTrue();
});
