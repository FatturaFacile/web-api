<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Controlling\Models\BudgetLine;
use Modules\Controlling\Models\BudgetPlan;
use Modules\Controlling\Support\Decimal;
use RuntimeException;

/**
 * Budgets, rolling forecasts and variance analysis over the analytical ledger: plan creation (blank, cloned with an uplift,
 * seeded from prior-year actuals with their seasonality), the draft / submitted / approved / locked workflow, grid edits with
 * seasonality redistribution, the rolling forecast (closed months actual + open months projected = estimate at completion)
 * and the variance decomposition with the executive bridge from budget margin to actual margin.
 */
final class BudgetVarianceService
{
    public const array RevenueNatures = ['revenue'];

    public const array CogsNatures = ['direct_material', 'direct_labor'];

    public const array OverheadNatures = ['variable_overhead', 'fixed_overhead', 'opex'];

    /** group_by => [ledger column, plan line column] */
    private const array Groups = [
        'nature' => ['nature_type', 'nature_type'], 'cost_center' => ['cost_center_id', 'cost_center_id'], 'job' => ['job_id', 'job_id'],
        'product_family' => ['product_family_id', 'product_family_id'], 'customer' => ['customer_id', 'customer_id'], 'agent' => ['sales_agent_id', 'sales_agent_id'],
        'department' => ['cost_center_id', 'production_department_id'], 'geography' => ['@country', 'country_code'],
    ];

    private const array DimensionColumns = ['account_code', 'cost_center_id', 'job_id', 'product_family_id', 'customer_id', 'country_code', 'region_code', 'sales_agent_id', 'production_department_id'];

    // ---------------------------------------------------------------------------------------------------- plans

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlan(string $tenantId, array $data): BudgetPlan
    {
        $version = 1 + (int) BudgetPlan::query()->where('tenant_id', $tenantId)->where('fiscal_year', $data['fiscal_year'])->where('plan_type', $data['plan_type'] ?? 'annual_budget')->max('version_number');

        return BudgetPlan::create([
            'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => $data['name'], 'fiscal_year' => $data['fiscal_year'], 'plan_type' => $data['plan_type'] ?? 'annual_budget',
            'version_number' => $data['version_number'] ?? $version, 'parent_plan_id' => $data['parent_plan_id'] ?? null, 'cutoff_month' => $data['cutoff_month'] ?? null,
            'status' => 'draft', 'currency_code' => $data['currency_code'] ?? 'EUR', 'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Copy a plan, its lines scaled by the uplift percentage.
     */
    public function clonePlan(BudgetPlan $source, string $code, string $name, string $uplift = '0', ?string $planType = null): BudgetPlan
    {
        return DB::transaction(function () use ($source, $code, $name, $uplift, $planType): BudgetPlan {
            $plan = $this->createPlan((string) $source->tenant_id, [
                'code' => $code, 'name' => $name, 'fiscal_year' => $source->fiscal_year, 'plan_type' => $planType ?? $source->plan_type, 'parent_plan_id' => $source->id,
                'cutoff_month' => $source->cutoff_month, 'currency_code' => $source->currency_code, 'notes' => $source->notes,
            ]);
            $factor = Decimal::add(1, Decimal::div($uplift, 100, 8));
            foreach ($source->lines()->get() as $line) {
                $attributes = $line->getAttributes();
                unset($attributes['id'], $attributes['created_at'], $attributes['updated_at'], $attributes['deleted_at']);
                $attributes['plan_id'] = $plan->id;
                $months = [];
                foreach (BudgetLine::monthColumns() as $column) {
                    $months[$column] = Decimal::mul($line->getAttribute($column), $factor);
                }
                $this->storeLine($plan, $attributes, $months, null);
            }

            return $plan;
        });
    }

    /**
     * Plan seeded from the prior-year actuals of the analytical ledger: every revenue and cost line by nature and center
     * takes the monthly shape (seasonality) of its own prior-year actuals, optionally with an uplift.
     */
    public function seedFromPriorYear(string $tenantId, int $year, string $code, string $name, string $uplift = '0'): BudgetPlan
    {
        return DB::transaction(function () use ($tenantId, $year, $code, $name, $uplift): BudgetPlan {
            $plan = $this->createPlan($tenantId, ['code' => $code, 'name' => $name, 'fiscal_year' => $year]);
            $groups = DB::table('analytical_ledger_entries')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('fiscal_year', $year - 1)
                ->where('source_type', '<>', 'overhead_allocation')
                ->selectRaw('nature_type, cost_center_id, job_id, product_family_id, customer_id, sales_agent_id')->groupBy('nature_type', 'cost_center_id', 'job_id', 'product_family_id', 'customer_id', 'sales_agent_id')->get();
            $factor = Decimal::add(1, Decimal::div($uplift, 100, 8));
            foreach ($groups as $group) {
                $filters = array_map('strval', array_filter((array) $group, static fn ($v): bool => $v !== null));
                $monthly = $this->monthlyActuals($tenantId, $year - 1, $filters);
                $total = Decimal::mul(array_sum(array_map(static fn (array $m): float => (float) $m['amount'], $monthly)), $factor);
                $quantity = Decimal::mul(array_sum(array_map(static fn (array $m): float => (float) $m['quantity'], $monthly)), $factor);
                $weights = array_map(static fn (array $m): string => Decimal::abs($m['amount']), $monthly);
                $attributes = ['tenant_id' => $tenantId, 'plan_id' => $plan->id, 'nature_type' => $group->nature_type, 'cost_center_id' => $group->cost_center_id, 'job_id' => $group->job_id,
                    'product_family_id' => $group->product_family_id, 'customer_id' => $group->customer_id, 'sales_agent_id' => $group->sales_agent_id, 'target_quantity' => $quantity, 'distribution_driver' => 'prior_year_seasonality'];
                $this->storeLine($plan, $attributes, $this->months(Decimal::spread($total, $weights)), null);
            }

            return $plan;
        });
    }

    /**
     * Batch upsert of grid lines. A line gives its twelve months, or an annual total with a distribution driver.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<BudgetLine>
     */
    public function bulkUpsert(BudgetPlan $plan, array $lines): array
    {
        if ($plan->status !== 'draft') {
            throw new RuntimeException('Only a draft plan can be edited.');
        }

        return DB::transaction(function () use ($plan, $lines): array {
            $saved = [];
            foreach ($lines as $payload) {
                $existing = isset($payload['id']) ? BudgetLine::query()->where('plan_id', $plan->id)->findOrFail($payload['id']) : null;
                $months = $this->monthsFromPayload($plan, $payload, $existing);
                if ($plan->plan_type === 'rolling_forecast' && $plan->cutoff_month !== null && $existing !== null) {
                    foreach (range(1, $plan->cutoff_month) as $m) {
                        $column = sprintf('month_%02d', $m);
                        if (Decimal::cmp($months[$column], $existing->getAttribute($column)) !== 0) {
                            throw new RuntimeException(sprintf('Month %d is a closed actual month of this rolling forecast.', $m));
                        }
                    }
                }
                $attributes = array_intersect_key($payload, array_flip([...self::DimensionColumns, 'nature_type', 'target_quantity', 'target_margin_percent', 'distribution_driver']));
                $attributes['plan_id'] = $plan->id;
                $saved[] = $this->storeLine($plan, $attributes, $months, $existing);
            }

            return $saved;
        });
    }

    public function submit(BudgetPlan $plan): BudgetPlan
    {
        return $this->transition($plan, 'draft', 'submitted');
    }

    public function approve(BudgetPlan $plan, ?string $userId): BudgetPlan
    {
        $plan = $this->transition($plan, 'submitted', 'approved');
        $plan->update(['approved_by' => $userId, 'approved_at' => now()]);

        return $plan;
    }

    public function lock(BudgetPlan $plan): BudgetPlan
    {
        return $this->transition($plan, 'approved', 'locked');
    }

    /**
     * Rolling forecast of a plan: the months up to the cutoff are the ledger actuals, the later ones are projected
     * (run_rate: average of the last closed months; budget: the budget itself) or overridden by the planner.
     *
     * @param  array<string, array<int, string|int|float>>  $overrides  budget line id => month => amount
     */
    public function generateRollingForecast(BudgetPlan $budget, int $cutoff, string $method = 'run_rate', array $overrides = [], ?string $code = null): BudgetPlan
    {
        if ($cutoff < 1 || $cutoff > 12 || ! in_array($method, ['run_rate', 'budget'], true)) {
            throw new InvalidArgumentException('Invalid cutoff month or projection method.');
        }
        $tenantId = (string) $budget->tenant_id;

        return DB::transaction(function () use ($budget, $cutoff, $method, $overrides, $code, $tenantId): BudgetPlan {
            $plan = $this->createPlan($tenantId, [
                'code' => $code ?? $budget->code.'-RF'.$cutoff, 'name' => $budget->name.' — rolling forecast M'.$cutoff, 'fiscal_year' => $budget->fiscal_year,
                'plan_type' => 'rolling_forecast', 'parent_plan_id' => $budget->id, 'cutoff_month' => $cutoff, 'currency_code' => $budget->currency_code,
            ]);
            foreach ($budget->lines()->get() as $line) {
                $actuals = $this->monthlyActuals($tenantId, $budget->fiscal_year, $this->lineFilters($line));
                $closed = array_slice($actuals, 0, $cutoff, true);
                $window = array_slice(array_column($closed, 'amount'), -min(3, $cutoff));
                $rateAmount = $window === [] ? '0.0000' : Decimal::div(array_sum(array_map(static fn ($a): float => (float) $a, $window)), count($window));
                $windowQty = array_slice(array_column($closed, 'quantity'), -min(3, $cutoff));
                $rateQty = $windowQty === [] ? '0.0000' : Decimal::div(array_sum(array_map(static fn ($a): float => (float) $a, $windowQty)), count($windowQty));
                $months = [];
                $quantity = '0.0000';
                foreach (range(1, 12) as $m) {
                    $column = sprintf('month_%02d', $m);
                    if ($m <= $cutoff) {
                        $months[$column] = Decimal::norm($actuals[$m]['amount']);
                        $quantity = Decimal::add($quantity, $actuals[$m]['quantity']);
                    } elseif (isset($overrides[$line->id][$m])) {
                        $months[$column] = Decimal::norm($overrides[$line->id][$m]);
                    } elseif ($method === 'budget') {
                        $months[$column] = Decimal::norm($line->getAttribute($column));
                        $quantity = Decimal::add($quantity, Decimal::mul($line->target_quantity, Decimal::div(1, 12, 8)));
                    } else {
                        $months[$column] = $rateAmount;
                        $quantity = Decimal::add($quantity, $rateQty);
                    }
                }
                $attributes = array_intersect_key($line->getAttributes(), array_flip([...self::DimensionColumns, 'nature_type']));
                $attributes['plan_id'] = $plan->id;
                $attributes['target_quantity'] = $quantity;
                $attributes['distribution_driver'] = 'manual';
                $this->storeLine($plan, $attributes, $months, null);
            }

            return $plan;
        });
    }

    public function activePlanId(string $tenantId, int $year, string $type): ?string
    {
        $id = BudgetPlan::query()->where('tenant_id', $tenantId)->where('fiscal_year', $year)->where('plan_type', $type)
            ->orderByRaw("CASE status WHEN 'locked' THEN 0 WHEN 'approved' THEN 1 WHEN 'submitted' THEN 2 ELSE 3 END")->orderByDesc('version_number')->orderByDesc('created_at')->value('id');

        return $id === null ? null : (string) $id;
    }

    // ------------------------------------------------------------------------------------------------ analysis

    /**
     * Revenue, cost and margin of the plan lines in the months, by the plan column (the whole plan under key '').
     *
     * @param  list<int>  $months
     * @param  array<string, string>  $filters
     * @return array<string, array{revenue: string, cost: string, margin: string}>
     */
    public function planTotals(string $planId, array $months, ?string $groupColumn, array $filters = []): array
    {
        $allowed = [...self::DimensionColumns, 'nature_type'];
        if ($groupColumn !== null && ! in_array($groupColumn, $allowed, true)) {
            return [];
        }
        $expr = implode(' + ', array_map(static fn (int $m): string => sprintf('month_%02d', $m), $months)) ?: '0';
        $query = DB::table('controlling_budget_lines')->where('plan_id', $planId)->whereNull('deleted_at');
        foreach ($filters as $column => $value) {
            if (in_array($column, $allowed, true)) {
                $query->where($column, $value);
            }
        }
        $select = "SUM(CASE WHEN nature_type = 'revenue' THEN {$expr} ELSE 0 END) AS revenue, SUM(CASE WHEN nature_type <> 'revenue' THEN {$expr} ELSE 0 END) AS cost";
        if ($groupColumn !== null) {
            $select .= ", {$groupColumn} AS grp";
            $query->groupBy($groupColumn);
        }
        $out = [];
        foreach ($query->selectRaw($select)->get() as $row) {
            $out[(string) ($row->grp ?? '')] = $this->totals(Decimal::norm(number_format((float) $row->revenue, 4, '.', '')), Decimal::norm(number_format((float) $row->cost, 4, '.', '')));
        }

        return $out;
    }

    /**
     * Variance of the actuals against budget, rolling forecast and prior year, by one dimension.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function variance(string $tenantId, array $query): array
    {
        $groupBy = (string) ($query['group_by'] ?? 'nature');
        if (! isset(self::Groups[$groupBy])) {
            throw new InvalidArgumentException("Unknown group_by {$groupBy}.");
        }
        [$ledgerColumn, $planColumn] = self::Groups[$groupBy];
        $budget = $this->plan($tenantId, $query['budget_id'] ?? null);
        $forecast = $this->plan($tenantId, $query['forecast_id'] ?? null);
        $year = (int) ($query['fiscal_year'] ?? ($budget instanceof BudgetPlan ? $budget->fiscal_year : now()->year));
        $mode = (string) ($query['period_mode'] ?? 'ytd');
        $month = (int) ($query['month'] ?? ($forecast instanceof BudgetPlan && $forecast->cutoff_month !== null ? $forecast->cutoff_month : now()->month));
        $months = match ($mode) {
            'monthly' => [$month], 'full_year_eac' => range(1, 12), 'ytd' => range(1, $month),
            default => throw new InvalidArgumentException('Unknown period_mode.'),
        };

        $actual = $this->actualTotals($tenantId, $year, $mode === 'full_year_eac' ? range(1, $month) : $months, $ledgerColumn);
        $prior = $this->actualTotals($tenantId, $year - 1, $months, $ledgerColumn);
        $budgetTotals = $budget instanceof BudgetPlan ? $this->planTotals((string) $budget->id, $months, $planColumn) : [];
        $forecastTotals = $forecast instanceof BudgetPlan ? $this->planTotals((string) $forecast->id, $months, $planColumn) : [];
        $eac = $forecast instanceof BudgetPlan ? $this->planTotals((string) $forecast->id, range(1, 12), $planColumn) : [];
        $empty = ['revenue' => '0.0000', 'cost' => '0.0000', 'margin' => '0.0000'];

        if ($mode === 'full_year_eac') {
            // Actual to date plus the budget of the open months, unless the forecast already holds the estimate.
            $open = $budget instanceof BudgetPlan && $month < 12 ? $this->planTotals((string) $budget->id, range($month + 1, 12), $planColumn) : [];
            $actualEac = [];
            foreach (array_unique([...array_keys($actual), ...array_keys($open)]) as $key) {
                $a = $actual[$key] ?? $empty;
                $o = $open[$key] ?? $empty;
                $actualEac[$key] = $this->totals(Decimal::add($a['revenue'], $o['revenue']), Decimal::add($a['cost'], $o['cost']));
            }
            $actual = $forecast instanceof BudgetPlan ? $eac : $actualEac;
        }

        $keys = array_values(array_unique(array_map('strval', [...array_keys($actual), ...array_keys($budgetTotals), ...array_keys($forecastTotals), ...array_keys($prior)])));
        $labels = $this->groupLabels($groupBy, $keys);
        $rows = [];
        foreach ($keys as $key) {
            $a = $actual[$key] ?? $empty;
            $b = $budgetTotals[$key] ?? $empty;
            $variance = Decimal::sub($a['margin'], $b['margin']);
            $rows[] = [
                'key' => $key, 'label' => $labels[$key] ?? ($key === '' ? '—' : $key),
                'actual' => $a, 'budget' => $b, 'forecast' => $forecastTotals[$key] ?? $empty, 'prior_year' => $prior[$key] ?? $empty, 'eac' => $eac[$key] ?? $empty,
                'variance' => $variance, 'variance_percent' => Decimal::percent($variance, Decimal::abs($b['margin'])), 'favorable' => Decimal::cmp($variance, 0) >= 0,
            ];
        }
        usort($rows, static fn (array $x, array $y): int => strcmp($x['label'], $y['label']));
        $sum = static fn (string $source): string => array_reduce($rows, static fn (string $c, array $r): string => Decimal::add($c, $r[$source]['margin']), '0.0000');

        return [
            'group_by' => $groupBy, 'period_mode' => $mode, 'fiscal_year' => $year, 'month' => $month,
            'budget_id' => $budget?->id, 'forecast_id' => $forecast?->id, 'data' => $rows,
            'totals' => ['actual_margin' => $sum('actual'), 'budget_margin' => $sum('budget'), 'forecast_margin' => $sum('forecast'), 'prior_year_margin' => $sum('prior_year'), 'eac_margin' => $sum('eac')],
        ];
    }

    /**
     * Executive bridge from budget margin to actual margin: budget margin + sales volume + price/mix + direct COGS +
     * overhead/OPEX variances = actual margin, exactly (the rounding residual goes to the largest step).
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function waterfall(string $tenantId, array $query): array
    {
        $budget = $this->plan($tenantId, $query['budget_id'] ?? null) ?? throw new InvalidArgumentException('A budget is required.');
        $forecast = $this->plan($tenantId, $query['forecast_id'] ?? null);
        $year = (int) $budget->fiscal_year;
        $mode = (string) ($query['period_mode'] ?? 'ytd');
        $month = (int) ($query['month'] ?? ($forecast instanceof BudgetPlan && $forecast->cutoff_month !== null ? $forecast->cutoff_month : now()->month));
        $months = match ($mode) {
            'monthly' => [$month], 'full_year_eac' => range(1, 12), 'ytd' => range(1, $month),
            default => throw new InvalidArgumentException('Unknown period_mode.'),
        };

        $b = $this->bridgeFigures($this->planBuckets((string) $budget->id, $months));
        if ($mode === 'full_year_eac' && $forecast instanceof BudgetPlan) {
            $a = $this->bridgeFigures($this->planBuckets((string) $forecast->id, $months));
        } else {
            $a = $this->bridgeFigures($this->ledgerBuckets($tenantId, $year, $mode === 'full_year_eac' ? range(1, $month) : $months));
        }

        $flex = Decimal::isZero($b['quantity']) ? '1' : Decimal::div($a['quantity'], $b['quantity'], 10);
        $budgetGross = Decimal::sub($b['revenue'], $b['cogs']);
        $volume = Decimal::isZero($b['quantity']) ? '0.0000' : Decimal::mul(Decimal::sub($a['quantity'], $b['quantity']), Decimal::div($budgetGross, $b['quantity'], 8));
        $price = Decimal::sub($a['revenue'], Decimal::mul($b['revenue'], $flex));
        $cogs = Decimal::neg(Decimal::sub($a['cogs'], Decimal::mul($b['cogs'], $flex)));
        $overhead = Decimal::neg(Decimal::sub($a['overhead'], $b['overhead']));
        $budgetMargin = Decimal::sub($budgetGross, $b['overhead']);
        $actualMargin = Decimal::sub(Decimal::sub($a['revenue'], $a['cogs']), $a['overhead']);

        // The volume, price/mix and COGS effects add up to the gross margin variance by construction; the rounding of the
        // four steps leaves at most a few 1e-4, which is booked on the largest step so that the walk is exact.
        $steps = ['volume' => $volume, 'price_mix' => $price, 'direct_cogs' => $cogs, 'overhead_opex' => $overhead];
        $residual = Decimal::sub($actualMargin, Decimal::add($budgetMargin, array_reduce($steps, static fn (string $c, string $s): string => Decimal::add($c, $s), '0.0000')));
        if (! Decimal::isZero($residual)) {
            $largest = (string) array_key_first($steps);
            foreach ($steps as $key => $value) {
                if (bccomp(Decimal::abs($value), Decimal::abs($steps[$largest]), 4) > 0) {
                    $largest = (string) $key;
                }
            }
            $steps[$largest] = Decimal::add($steps[$largest], $residual);
        }

        $bridge = [['key' => 'budget_margin', 'label' => 'Budget margin', 'value' => $budgetMargin, 'type' => 'total']];
        foreach ($steps as $key => $value) {
            $bridge[] = ['key' => $key, 'label' => Str::headline($key), 'value' => $value, 'type' => 'delta', 'favorable' => Decimal::cmp($value, 0) >= 0];
        }
        $bridge[] = ['key' => 'actual_margin', 'label' => 'Actual margin', 'value' => $actualMargin, 'type' => 'total'];

        return ['budget_id' => $budget->id, 'forecast_id' => $forecast?->id, 'period_mode' => $mode, 'month' => $month, 'budget' => $b, 'actual' => $a, 'steps' => $bridge, 'residual_adjustment' => $residual];
    }

    // -------------------------------------------------------------------------------------------------- helpers

    /**
     * Ledger revenue and cost by group in the months of the year.
     *
     * @param  list<int>  $months
     * @return array<string, array{revenue: string, cost: string, margin: string}>
     */
    private function actualTotals(string $tenantId, int $year, array $months, string $ledgerColumn): array
    {
        $query = DB::table('analytical_ledger_entries as e')->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->where('e.fiscal_year', $year)->whereIn('e.fiscal_month', $months ?: [0]);
        if ($ledgerColumn === '@country') {
            $query->leftJoin('contacts as c', 'c.id', '=', 'e.customer_id');
            $group = 'c.country';
        } else {
            $group = 'e.'.$ledgerColumn;
        }
        $rows = $query->selectRaw("SUM(CASE WHEN e.nature_type = 'revenue' THEN e.amount ELSE 0 END) AS revenue, SUM(CASE WHEN e.nature_type <> 'revenue' THEN e.amount ELSE 0 END) AS cost, {$group} AS grp")->groupByRaw($group)->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) ($row->grp ?? '')] = $this->totals(Decimal::norm(number_format((float) $row->revenue, 4, '.', '')), Decimal::norm(number_format((float) $row->cost, 4, '.', '')));
        }

        return $out;
    }

    /**
     * @return array{revenue: string, cost: string, margin: string}
     */
    private function totals(string $revenue, string $cost): array
    {
        return ['revenue' => $revenue, 'cost' => $cost, 'margin' => Decimal::sub($revenue, $cost)];
    }

    /**
     * Amount and quantity of the ledger by month for the filters (month => values), 1..12.
     *
     * @param  array<string, string>  $filters
     * @return array<int, array{amount: string, quantity: string}>
     */
    private function monthlyActuals(string $tenantId, int $year, array $filters): array
    {
        $query = DB::table('analytical_ledger_entries')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('fiscal_year', $year)->where('source_type', '<>', 'overhead_allocation');
        foreach ($filters as $column => $value) {
            if (in_array($column, ['nature_type', 'cost_center_id', 'job_id', 'product_family_id', 'customer_id', 'sales_agent_id', 'account_code'], true)) {
                $query->where($column, $value);
            }
        }
        $byMonth = $query->selectRaw('fiscal_month, SUM(amount) AS amount, SUM(quantity) AS quantity')->groupBy('fiscal_month')->get()->keyBy('fiscal_month');
        $out = [];
        foreach (range(1, 12) as $m) {
            $row = $byMonth->get($m);
            $out[$m] = ['amount' => $row ? Decimal::norm(number_format((float) $row->amount, 4, '.', '')) : '0.0000', 'quantity' => $row ? Decimal::norm(number_format((float) $row->quantity, 4, '.', '')) : '0.0000'];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function lineFilters(BudgetLine $line): array
    {
        $filters = ['nature_type' => $line->nature_type];
        foreach (['cost_center_id', 'job_id', 'product_family_id', 'customer_id', 'sales_agent_id', 'account_code'] as $column) {
            $value = $line->getAttribute($column);
            if ($value !== null) {
                $filters[$column] = (string) $value;
            }
        }

        return $filters;
    }

    /**
     * @param  list<int>  $months
     * @return array{revenue: string, cogs: string, overhead: string, quantity: string}
     */
    private function planBuckets(string $planId, array $months): array
    {
        $expr = implode(' + ', array_map(static fn (int $m): string => sprintf('month_%02d', $m), $months)) ?: '0';
        $lines = DB::table('controlling_budget_lines')->where('plan_id', $planId)->whereNull('deleted_at')->selectRaw("nature_type, {$expr} AS amount, annual_total, target_quantity")->get();
        $out = ['revenue' => '0.0000', 'cogs' => '0.0000', 'overhead' => '0.0000', 'quantity' => '0.0000'];
        foreach ($lines as $line) {
            $amount = Decimal::norm(number_format((float) $line->amount, 4, '.', ''));
            $bucket = $this->bucket((string) $line->nature_type);
            $out[$bucket] = Decimal::add($out[$bucket], $amount);
            if ($bucket === 'revenue') {
                $share = Decimal::isZero((string) $line->annual_total) ? Decimal::div(count($months), 12, 8) : Decimal::div($amount, (string) $line->annual_total, 8);
                $out['quantity'] = Decimal::add($out['quantity'], Decimal::mul($line->target_quantity, $share));
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $months
     * @return array{revenue: string, cogs: string, overhead: string, quantity: string}
     */
    private function ledgerBuckets(string $tenantId, int $year, array $months): array
    {
        $rows = DB::table('analytical_ledger_entries')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('fiscal_year', $year)->whereIn('fiscal_month', $months ?: [0])
            ->selectRaw('nature_type, SUM(amount) AS amount, SUM(quantity) AS quantity')->groupBy('nature_type')->get();
        $out = ['revenue' => '0.0000', 'cogs' => '0.0000', 'overhead' => '0.0000', 'quantity' => '0.0000'];
        foreach ($rows as $row) {
            $bucket = $this->bucket((string) $row->nature_type);
            $out[$bucket] = Decimal::add($out[$bucket], number_format((float) $row->amount, 4, '.', ''));
            if ($bucket === 'revenue') {
                $out['quantity'] = Decimal::add($out['quantity'], number_format((float) $row->quantity, 4, '.', ''));
            }
        }

        return $out;
    }

    private function bucket(string $nature): string
    {
        return $nature === 'revenue' ? 'revenue' : (in_array($nature, self::CogsNatures, true) ? 'cogs' : 'overhead');
    }

    /**
     * @param  array{revenue: string, cogs: string, overhead: string, quantity: string}  $buckets
     * @return array{revenue: string, cogs: string, overhead: string, quantity: string, margin: string}
     */
    private function bridgeFigures(array $buckets): array
    {
        return $buckets + ['margin' => Decimal::sub(Decimal::sub($buckets['revenue'], $buckets['cogs']), $buckets['overhead'])];
    }

    private function plan(string $tenantId, mixed $id): ?BudgetPlan
    {
        return $id === null || $id === '' ? null : BudgetPlan::query()->where('tenant_id', $tenantId)->findOrFail($id);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function groupLabels(string $groupBy, array $keys): array
    {
        $keys = array_values(array_filter($keys, static fn (string $k): bool => $k !== ''));
        if ($keys === []) {
            return [];
        }
        $named = static fn (string $table, string $expr): array => DB::table($table)->whereIn('id', $keys)->selectRaw("id, {$expr} AS label")->pluck('label', 'id')->map(static fn ($l): string => (string) $l)->all();

        return match ($groupBy) {
            'cost_center', 'department' => $named('analytical_cost_centers', "code || ' - ' || name"),
            'job' => $named('analytical_jobs', "code || ' - ' || name"),
            'product_family' => $named('product_categories', 'name'),
            'customer' => $named('contacts', "COALESCE(NULLIF(company_name, ''), TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')))"),
            'agent' => $named('sales_representatives', 'name'),
            default => array_combine($keys, array_map(static fn (string $k): string => Str::headline($k), $keys)),
        };
    }

    private function transition(BudgetPlan $plan, string $from, string $to): BudgetPlan
    {
        if ($plan->status !== $from) {
            throw new RuntimeException("The plan must be {$from} to become {$to}.");
        }
        $plan->update(['status' => $to]);

        return $plan;
    }

    /**
     * @param  array<string|int, string>  $spread  shares by position
     * @return array<string, string>
     */
    private function months(array $spread): array
    {
        $out = [];
        $values = array_values($spread);
        foreach (BudgetLine::monthColumns() as $i => $column) {
            $out[$column] = $values[$i] ?? '0.0000';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function monthsFromPayload(BudgetPlan $plan, array $payload, ?BudgetLine $existing): array
    {
        $given = [];
        foreach (BudgetLine::monthColumns() as $column) {
            if (array_key_exists($column, $payload)) {
                $given[$column] = Decimal::norm($payload[$column]);
            }
        }
        if ($given !== []) {
            $out = [];
            foreach (BudgetLine::monthColumns() as $column) {
                $out[$column] = $given[$column] ?? ($existing !== null ? Decimal::norm($existing->getAttribute($column)) : '0.0000');
            }

            return $out;
        }
        if (! isset($payload['annual_total'])) {
            throw new InvalidArgumentException('A line needs its months or an annual total.');
        }
        $driver = (string) ($payload['distribution_driver'] ?? 'equal_12');
        $weights = match ($driver) {
            'prior_year_seasonality' => array_map(static fn (array $m): string => Decimal::abs($m['amount']), $this->monthlyActuals($plan->tenant_id, $plan->fiscal_year - 1, array_map('strval', array_filter(['nature_type' => $payload['nature_type'] ?? ($existing instanceof BudgetLine ? $existing->nature_type : null), 'cost_center_id' => $payload['cost_center_id'] ?? ($existing instanceof BudgetLine ? $existing->cost_center_id : null), 'job_id' => $payload['job_id'] ?? ($existing instanceof BudgetLine ? $existing->job_id : null)], static fn ($v): bool => $v !== null)))),
            'custom_weights' => array_map(static fn ($w): string => Decimal::norm($w), array_values((array) ($payload['weights'] ?? []))),
            default => array_fill(0, 12, '1'),
        };
        if (count($weights) !== 12 || Decimal::isZero((string) array_sum(array_map(static fn ($w): float => (float) $w, $weights)))) {
            $weights = array_fill(0, 12, '1');
        }

        return $this->months(Decimal::spread($payload['annual_total'], $weights));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, string>  $months
     */
    private function storeLine(BudgetPlan $plan, array $attributes, array $months, ?BudgetLine $existing): BudgetLine
    {
        $total = array_reduce($months, static fn (string $c, string $m): string => Decimal::add($c, $m), '0.0000');
        $attributes = array_merge($attributes, $months, ['annual_total' => $total, 'target_amount' => $total, 'tenant_id' => $plan->tenant_id, 'plan_id' => $plan->id]);
        if ($existing !== null) {
            $existing->update($attributes);

            return $existing;
        }
        $attributes['nature_type'] ??= throw new InvalidArgumentException('A line needs its nature.');

        return BudgetLine::create($attributes);
    }
}
