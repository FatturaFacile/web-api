<?php

declare(strict_types=1);

namespace Modules\Controlling\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ControllingDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Controlling\Models\AnalyticalLedgerEntry;
use Modules\Controlling\Models\BudgetLine;
use Modules\Controlling\Models\BudgetPlan;
use Modules\Controlling\Models\CostAllocationRun;
use Modules\Controlling\Models\FinancialSnapshot;
use Modules\Controlling\Services\BudgetVarianceService;
use Modules\Controlling\Services\CostAllocationEngineService;
use Modules\Controlling\Services\FinancialKpiService;
use Modules\Controlling\Services\MultidimensionalMarginService;
use Modules\Controlling\Support\Decimal;
use Throwable;

/**
 * End-to-end reconciliation of the controlling suite on the demo dataset: allocation balance and reversal, margin tiers and
 * credit note deduction across the eight dimensions, working capital identities, budget seasonality and the waterfall bridge.
 */
final class VerifyIntegrityCommand extends Command
{
    protected $signature = 'controlling:verify-integrity {--tenant= : Tenant id to verify (default: the demo tenant)} {--seed : Seed the demo dataset first (Scenario A)}';

    protected $description = 'Seed and verify the controlling suite: allocations, margins, working capital, budgets and bridge';

    /** @var list<array{0: string, 1: bool, 2: string}> */
    private array $results = [];

    public function handle(ControllingDemoSeeder $seeder, TenantContext $context): int
    {
        $tenant = $this->option('tenant') ? Tenant::query()->findOrFail($this->option('tenant')) : Tenant::query()->where('name', 'Controlling Demo')->first();
        if ($this->option('seed') || $tenant === null) {
            $this->components->info('Scenario A: seeding the demo dataset');
            $seeder->run($tenant);
            $tenant = Tenant::query()->where('name', 'Controlling Demo')->firstOrFail();
        }
        $context->setTenant($tenant);
        $tenantId = (string) $tenant->id;

        foreach ([
            'A: dataset' => fn () => $this->scenarioA($tenantId),
            'B: allocation and reversal' => fn () => $this->scenarioB($tenantId),
            'C: margin tiers and dimensions' => fn () => $this->scenarioC($tenantId),
            'D: working capital ratios' => fn () => $this->scenarioD($tenantId),
            'E: budget, forecast and bridge' => fn () => $this->scenarioE($tenantId),
        ] as $name => $scenario) {
            try {
                foreach ($scenario() as [$check, $ok, $detail]) {
                    $this->results[] = ["{$name} / {$check}", $ok, $detail];
                }
            } catch (Throwable $exception) {
                $this->results[] = [$name, false, $exception->getMessage()];
            }
        }
        $context->clear();
        $this->table(['Check', 'Result', 'Detail'], array_map(static fn (array $r): array => [$r[0], $r[1] ? 'PASS' : 'FAIL', $r[2]], $this->results));
        $failed = count(array_filter($this->results, static fn (array $r): bool => ! $r[1]));
        $failed === 0 ? $this->components->info('All checks passed.') : $this->components->error("{$failed} check(s) failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function scenarioA(string $tenantId): array
    {
        $count = static fn (string $table): int => DB::table($table)->where('tenant_id', $tenantId)->count();

        return [
            ['cost centers', $count('analytical_cost_centers') >= 6, (string) $count('analytical_cost_centers')],
            ['jobs', $count('analytical_jobs') >= 3, (string) $count('analytical_jobs')],
            ['products and families', DB::table('products')->where('tenant_id', $tenantId)->count() >= 12 && DB::table('product_categories')->where('tenant_id', $tenantId)->count() >= 4, 'products + families'],
            ['credit notes present', DB::table('bi_sales_margin_facts')->where('tenant_id', $tenantId)->where('document_type', 'credit_note')->exists(), 'TD04 facts'],
            ['snapshots', $count('bi_financial_snapshots') >= 12, (string) $count('bi_financial_snapshots')],
        ];
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function scenarioB(string $tenantId): array
    {
        $engine = app(CostAllocationEngineService::class);
        $year = ControllingDemoSeeder::Year;
        $month = 3;
        $cc100 = (string) DB::table('analytical_cost_centers')->where('tenant_id', $tenantId)->where('code', 'CC-100')->value('id');
        $cc200 = (string) DB::table('analytical_cost_centers')->where('tenant_id', $tenantId)->where('code', 'CC-200')->value('id');
        $balance = fn (string $id): string => Decimal::norm(number_format((float) DB::table('analytical_ledger_entries')->where('tenant_id', $tenantId)->where('cost_center_id', $id)->where('fiscal_year', $year)->where('fiscal_month', $month)->sum('amount'), 4, '.', ''));
        $allocationSum = fn (string $runId): string => Decimal::norm(number_format((float) DB::table('analytical_ledger_entries')->where('allocation_run_id', $runId)->sum('amount'), 4, '.', ''));
        $out = [];

        $run = CostAllocationRun::query()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('fiscal_month', $month)->where('status', 'posted')->first();
        $run ??= $engine->execute($tenantId, $year, $month);
        $out[] = ['run balances to zero', Decimal::isZero($allocationSum((string) $run->id)), 'sum(debits)+sum(credits)='.$allocationSum((string) $run->id)];
        $out[] = ['CC-100 and CC-200 net to zero after allocation', Decimal::isZero($balance($cc100)) && Decimal::isZero($balance($cc200)), $balance($cc100).' / '.$balance($cc200)];

        $engine->reverse($tenantId, (string) $run->id);
        $out[] = ['reversal nets the run to zero', Decimal::isZero($allocationSum((string) $run->id)), 'run '.$run->id];
        $out[] = ['reversal restores the source pools', Decimal::cmp($balance($cc100), 0) > 0 && Decimal::cmp($balance($cc200), 0) > 0, $balance($cc100)];
        $again = $engine->execute($tenantId, $year, $month);
        $out[] = ['re-execution balances and clears the sources', Decimal::isZero($allocationSum((string) $again->id)) && Decimal::isZero($balance($cc100)) && Decimal::isZero($balance($cc200)), 'run '.$again->id];

        return $out;
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function scenarioC(string $tenantId): array
    {
        $svc = app(MultidimensionalMarginService::class);
        $query = ['date_from' => ControllingDemoSeeder::Year.'-01-01', 'date_to' => ControllingDemoSeeder::Year.'-12-31'];
        $t = $svc->summary($tenantId, $query)['totals'];
        $out = [];
        $net = Decimal::sub(Decimal::sub($t['gross_revenue'], $t['discounts']), $t['credit_notes']);
        $out[] = ['net revenue = gross - discounts - credit notes', Decimal::cmp($net, $t['net_revenue']) === 0, "{$net} vs {$t['net_revenue']}"];
        $out[] = ['credit notes deducted', Decimal::cmp($t['credit_notes'], 0) > 0, $t['credit_notes']];
        $ordered = Decimal::cmp($t['gross_margin'], $t['commercial_margin']) >= 0 && Decimal::cmp($t['commercial_margin'], $t['full_industrial_margin']) >= 0;
        $out[] = ['gross >= commercial >= full industrial', $ordered && Decimal::cmp($t['allocated_overheads'], 0) > 0, "{$t['gross_margin']} / {$t['commercial_margin']} / {$t['full_industrial_margin']}"];
        foreach (MultidimensionalMarginService::Dimensions as $dimension) {
            $rows = $svc->explore($tenantId, $query + ['group_by' => $dimension, 'per_page' => 500])['data'];
            $sum = static fn (string $metric): string => array_reduce($rows, static fn (string $c, array $r): string => Decimal::add($c, $r['metrics'][$metric]), '0.0000');
            $ok = Decimal::cmp($sum('net_revenue'), $t['net_revenue']) === 0 && Decimal::cmp($sum('gross_margin'), $t['gross_margin']) === 0 && Decimal::cmp($sum('full_industrial_margin'), $t['full_industrial_margin']) === 0;
            $out[] = ["totals match by {$dimension}", $ok, count($rows).' rows'];
        }

        return $out;
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function scenarioD(string $tenantId): array
    {
        $kpis = app(FinancialKpiService::class);
        $data = $kpis->kpis($tenantId, ControllingDemoSeeder::Year.'-12-31');
        $c = $data['current'];
        $out = [];
        $ccn = Decimal::sub(Decimal::add($c['inventory'], $c['accounts_receivable']), $c['accounts_payable']);
        $out[] = ['CCN = inventory + AR - AP', Decimal::cmp($ccn, $c['ccn']) === 0, "{$ccn} vs {$c['ccn']}"];
        $ccc = Decimal::round(Decimal::sub(Decimal::add($c['dio'], $c['dso']), $c['dpo']), 2);
        $out[] = ['CCC = DIO + DSO - DPO', Decimal::cmp($ccc, $c['ccc']) === 0, "{$ccc} vs {$c['ccc']}"];
        $empty = $kpis->point(new FinancialSnapshot(['snapshot_date' => '2026-01-31', 'fiscal_year' => 2026, 'fiscal_month' => 1]), null);
        $out[] = ['zero denominators are safe', $empty['dso'] === '0.00' && $empty['dio'] === '0.00' && $empty['inventory_turnover'] === '0.00' && $empty['ccc'] === '0.00', implode(',', $empty['warnings'])];

        return $out;
    }

    /**
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function scenarioE(string $tenantId): array
    {
        $budgets = app(BudgetVarianceService::class);
        $year = ControllingDemoSeeder::Year;
        $plan = BudgetPlan::query()->where('tenant_id', $tenantId)->where('code', 'BUD-DEMO-V1')->first();
        if ($plan === null) {
            $plan = $budgets->seedFromPriorYear($tenantId, $year, 'BUD-DEMO-V1', 'Annual budget v1', '5');
            $budgets->approve($budgets->submit($plan), null);
        }
        $bad = 0;
        foreach ($plan->lines()->get() as $line) {
            $sum = array_reduce(BudgetLine::monthColumns(), static fn (string $c, string $col): string => Decimal::add($c, $line->{$col}), '0.0000');
            $bad += Decimal::cmp($sum, $line->annual_total) === 0 ? 0 : 1;
        }
        $out = [['sum(month_01..month_12) = annual_total on every line', $bad === 0 && $plan->lines()->count() > 0, $plan->lines()->count().' lines']];

        $forecast = BudgetPlan::query()->where('tenant_id', $tenantId)->where('plan_type', 'rolling_forecast')->where('parent_plan_id', $plan->id)->first()
            ?? $budgets->generateRollingForecast($plan, 8);
        foreach (['ytd', 'monthly', 'full_year_eac'] as $mode) {
            $w = $budgets->waterfall($tenantId, ['budget_id' => $plan->id, 'forecast_id' => $forecast->id, 'period_mode' => $mode, 'month' => 8]);
            $steps = collect($w['steps']);
            $walk = $steps->where('type', 'delta')->reduce(static fn (string $c, array $s): string => Decimal::add($c, $s['value']), $steps->firstWhere('key', 'budget_margin')['value']);
            $actual = $steps->firstWhere('key', 'actual_margin')['value'];
            $out[] = ["bridge sums to actual margin ({$mode})", Decimal::cmp($walk, $actual) === 0, "{$walk} vs {$actual}"];
        }
        $closedMatch = AnalyticalLedgerEntry::query()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->exists();
        $out[] = ['rolling forecast cutoff is month 8', (int) $forecast->cutoff_month === 8 && $closedMatch, 'cutoff '.$forecast->cutoff_month];

        return $out;
    }
}
