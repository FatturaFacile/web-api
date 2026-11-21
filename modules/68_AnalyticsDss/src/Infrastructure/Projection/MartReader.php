<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Projection;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;
use Modules\AnalyticsDss\Domain\ValueObjects\ProfitabilityInput;
use Modules\AnalyticsDss\Infrastructure\Support\AccountClassifier;
use Modules\AnalyticsDss\Infrastructure\Support\FxRateResolver;

/**
 * Read model of the data mart. Period figures come from the pre-aggregated daily snapshots (one row per legal entity and
 * day) so they stay well under the latency budget on large ledgers; only a cost center filter, which the snapshot grain
 * cannot express, falls back to the fact table. Every query is scoped to the tenant of the filter.
 */
final class MartReader
{
    private const array Movements = [
        'revenue' => 'revenue', 'cogs' => 'cogs', 'opex' => 'opex', 'depreciation' => 'depreciation', 'cash' => 'cash_movement',
        'receivable' => 'receivable_movement', 'payable' => 'payable_movement', 'inventory' => 'inventory_movement',
    ];

    public function __construct(private readonly FxRateResolver $fx, private readonly AccountClassifier $classifier) {}

    public static function dateKey(string $date): int
    {
        return (int) str_replace('-', '', substr($date, 0, 10));
    }

    /**
     * Natural-sign movements of the period in the reporting currency: revenue, cogs, opex, depreciation, cash, receivable,
     * payable and inventory.
     *
     * @return array<string, float>
     */
    public function movements(DssFilter $filter, string $from, string $to): array
    {
        $currency = strtolower($filter->currency);
        $result = array_fill_keys(array_keys(self::Movements), 0.0);

        if ($filter->costCenterIds === []) {
            $select = [];

            foreach (self::Movements as $alias => $column) {
                $select[] = "COALESCE(SUM({$column}_{$currency}), 0) as {$alias}";
            }

            $row = $this->snapshots($filter, $from, $to)->selectRaw(implode(', ', $select))->first();

            foreach ($result as $alias => $_) {
                $result[$alias] = round((float) ($row->{$alias} ?? 0.0), 4);
            }

            return $result;
        }

        $sign = ['revenue' => -1, 'payable' => -1];
        $groups = $this->facts($filter, $from, $to)->selectRaw("category, COALESCE(SUM(amount_{$currency}), 0) as total")->groupBy('category')->pluck('total', 'category');

        foreach ($result as $alias => $_) {
            $result[$alias] = round((float) ($groups[$alias] ?? 0.0) * ($sign[$alias] ?? 1), 4);
        }

        return $result;
    }

    /**
     * Receivables, payables, inventory and cash balances at the end of the day (cumulative ledger movements).
     *
     * @return array{receivable: float, payable: float, inventory: float, cash: float}
     */
    public function balances(DssFilter $filter, string $asOf): array
    {
        $movements = $this->movements($filter, '1900-01-01', $asOf);

        return ['receivable' => $movements['receivable'], 'payable' => $movements['payable'], 'inventory' => $movements['inventory'], 'cash' => $movements['cash']];
    }

    /**
     * Monthly revenue, cogs, opex and cash series (key YYYYMM) of the range, oldest first.
     *
     * @return array<int, array{revenue: float, cogs: float, opex: float, cash: float}>
     */
    public function monthlySeries(DssFilter $filter, string $from, string $to): array
    {
        $currency = strtolower($filter->currency);
        $series = [];

        if ($filter->costCenterIds === []) {
            $rows = $this->snapshots($filter, $from, $to)
                ->selectRaw("CAST(date_key / 100 AS INTEGER) as month_key, SUM(revenue_{$currency}) as revenue, SUM(cogs_{$currency}) as cogs, SUM(opex_{$currency}) as opex, SUM(cash_movement_{$currency}) as cash")
                ->groupByRaw('CAST(date_key / 100 AS INTEGER)')->orderBy('month_key')->get();

            foreach ($rows as $row) {
                $series[(int) $row->month_key] = ['revenue' => round((float) $row->revenue, 4), 'cogs' => round((float) $row->cogs, 4), 'opex' => round((float) $row->opex, 4), 'cash' => round((float) $row->cash, 4)];
            }

            return $series;
        }

        $rows = $this->facts($filter, $from, $to)
            ->selectRaw("CAST(date_key / 100 AS INTEGER) as month_key, category, SUM(amount_{$currency}) as total")
            ->groupByRaw('CAST(date_key / 100 AS INTEGER), category')->orderBy('month_key')->get();

        foreach ($rows as $row) {
            $month = (int) $row->month_key;
            $series[$month] ??= ['revenue' => 0.0, 'cogs' => 0.0, 'opex' => 0.0, 'cash' => 0.0];
            $key = ['revenue' => 'revenue', 'cogs' => 'cogs', 'opex' => 'opex', 'cash' => 'cash'][$row->category] ?? null;

            if ($key !== null) {
                $series[$month][$key] += round((float) $row->total * ($key === 'revenue' ? -1 : 1), 4);
            }
        }

        return $series;
    }

    /**
     * Commercial figures of the issued invoices of the range in the reporting currency.
     *
     * @return array{revenue: float, invoices: int, open_receivables: float, customers: array<string, array{label: string, revenue: float}>, win_rate_pct: float|null, units: float}
     */
    public function commercial(DssFilter $filter, string $from, string $to): array
    {
        $currency = strtolower($filter->currency);
        $rows = $this->velocity($filter, $from, $to)
            ->selectRaw("counterparty_id, COUNT(*) as invoices, COALESCE(SUM(amount_net_{$currency}), 0) as revenue, COALESCE(SUM(amount_open_{$currency}), 0) as open_amount")
            ->groupBy('counterparty_id')->get();

        $names = DB::table('dim_counterparty')->where('tenant_id', $filter->tenantId)->pluck('name', 'contact_id');
        $customers = [];
        $invoices = 0;
        $revenue = 0.0;
        $open = 0.0;

        foreach ($rows as $row) {
            $key = (string) ($row->counterparty_id ?? '');
            $customers[$key] = ['label' => (string) ($names[$key] ?? $key), 'revenue' => round((float) $row->revenue, 4)];
            $invoices += (int) $row->invoices;
            $revenue += (float) $row->revenue;
            $open += (float) $row->open_amount;
        }

        $quotes = DB::table('quotes')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->where('status', '!=', 'draft')->whereBetween('date', [$from, $to]);
        $total = (clone $quotes)->count();
        $won = (clone $quotes)->where(static fn ($q) => $q->whereIn('status', ['accepted', 'converted', 'won', 'signed'])->orWhereNotNull('converted_to_order_id'))->count();

        $units = (float) DB::table('invoice_items as i')->join('fact_commercial_velocity as v', 'v.invoice_id', '=', 'i.invoice_id')
            ->where('v.tenant_id', $filter->tenantId)->whereBetween('v.issue_date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn ($q) => $q->whereIn('v.legal_entity_id', $filter->legalEntityIds))
            ->whereNull('v.deleted_at')->whereNull('i.deleted_at')->sum('i.quantity');

        return ['revenue' => round($revenue, 4), 'invoices' => $invoices, 'open_receivables' => round($open, 4), 'customers' => $customers, 'win_rate_pct' => $total > 0 ? round($won / $total * 100.0, 2) : null, 'units' => $units];
    }

    /**
     * @return array{billable_hours: float, non_billable_hours: float, value_out: float, unit_cost_variance_value: float}
     */
    public function operations(DssFilter $filter, string $from, string $to): array
    {
        $row = DB::table('fact_operational_efficiency')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereBetween('date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn ($q) => $q->whereIn('legal_entity_id', $filter->legalEntityIds))
            ->selectRaw('COALESCE(SUM(billable_hours), 0) as billable, COALESCE(SUM(non_billable_hours), 0) as non_billable, COALESCE(SUM(value_out), 0) as value_out, COALESCE(SUM(unit_cost_variance * quantity_out), 0) as variance')->first();

        return ['billable_hours' => (float) $row->billable, 'non_billable_hours' => (float) $row->non_billable, 'value_out' => (float) $row->value_out, 'unit_cost_variance_value' => (float) $row->variance];
    }

    /**
     * Average days beyond the due date (settled or still open as of the end of the range) per customer.
     *
     * @return array<string, float>
     */
    public function paymentDelays(DssFilter $filter, string $from, string $to): array
    {
        $rows = $this->velocity($filter, $from, $to)->whereNotNull('due_date')->get(['counterparty_id', 'due_date', 'paid_date', 'amount_open']);
        $delays = [];

        foreach ($rows as $row) {
            if ($row->paid_date === null && (float) $row->amount_open <= 0.0) {
                continue;
            }

            $end = (string) ($row->paid_date ?? $to);
            $late = max((int) round((strtotime(substr($end, 0, 10)) - strtotime(substr((string) $row->due_date, 0, 10))) / 86400), 0);
            $delays[(string) $row->counterparty_id][] = $late;
        }

        return array_map(static fn (array $days): float => round(array_sum($days) / count($days), 2), $delays);
    }

    /**
     * Revenue and cost inputs per entity of a profitability dimension. Costs tagged to the entity in the ledger are
     * direct; the remaining pool is allocated by revenue share.
     *
     * @return list<ProfitabilityInput>
     */
    public function breakdown(DssFilter $filter, string $dimension, string $from, string $to): array
    {
        $currency = strtolower($filter->currency);
        $delays = $dimension === 'customer' ? $this->paymentDelays($filter, $from, $to) : [];
        [$revenue, $discounts, $labels] = $this->revenueByEntity($filter, $dimension, $from, $to);

        $column = ['customer' => 'counterparty_id', 'cost_center' => 'cost_center_id', 'legal_entity' => 'legal_entity_id'][$dimension] ?? null;
        $totals = $this->movements($filter, $from, $to);
        $tagged = ['cogs' => [], 'opex' => [], 'depreciation' => []];

        if ($column !== null) {
            $rows = $this->facts($filter, $from, $to)->whereIn('category', array_keys($tagged))->whereNotNull($column)
                ->selectRaw("{$column} as entity, category, COALESCE(SUM(amount_{$currency}), 0) as total")->groupBy($column, 'category')->get();

            foreach ($rows as $row) {
                $tagged[$row->category][(string) $row->entity] = round((float) $row->total, 4);
            }
        }

        if ($dimension === 'product') {
            $tagged['cogs'] = $this->productStandardCost($filter, $from, $to);
        }

        $positive = array_sum(array_map(static fn (float $v): float => max($v, 0.0), $revenue));
        $inputs = [];

        foreach ($revenue as $key => $gross) {
            $share = $positive > 0.0 ? max($gross, 0.0) / $positive : 0.0;
            $pool = static fn (string $category): float => max($totals[$category] - array_sum($tagged[$category]), 0.0) * $share;
            $direct = static fn (string $category): float => $tagged[$category][$key] ?? 0.0;

            $inputs[] = new ProfitabilityInput(
                key: $key, label: $labels[$key] ?? $key, grossRevenue: $gross + ($discounts[$key] ?? 0.0), discounts: $discounts[$key] ?? 0.0,
                cogs: $direct('cogs') + $pool('cogs'), variableCosts: 0.0, directOpex: $direct('opex'), allocatedOverhead: $pool('opex'),
                depreciation: $direct('depreciation') + $pool('depreciation'), averagePaymentDelayDays: $delays[$key] ?? 0.0,
            );
        }

        return $inputs;
    }

    /**
     * Approved (or latest) ledger budget of the range in the reporting currency, by analytical category.
     *
     * @return array{revenue: float, cogs: float, opex: float, depreciation: float, found: bool}
     */
    public function budget(DssFilter $filter, string $from, string $to): array
    {
        $result = ['revenue' => 0.0, 'cogs' => 0.0, 'opex' => 0.0, 'depreciation' => 0.0, 'found' => false];
        $budgets = DB::table('gl_budgets')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')->whereIn('status', ['approved', 'active', 'locked', 'draft'])
            ->when($filter->legalEntityIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('company_id', $filter->legalEntityIds)->orWhereNull('company_id')))
            ->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")->orderByDesc('version')->get();

        if ($budgets->isEmpty()) {
            return $result;
        }

        $years = DB::table('fiscal_years')->where('tenant_id', $filter->tenantId)->whereIn('id', $budgets->pluck('fiscal_year_id'))->get(['id', 'start_date', 'end_date'])->keyBy('id');
        $accounts = DB::table('chart_of_accounts')->where('tenant_id', $filter->tenantId)->get(['id', 'code', 'type', 'reconciliation_type'])->keyBy('id');
        $month = new DateTimeImmutable(substr($from, 0, 7).'-01');
        $last = new DateTimeImmutable($to);
        $used = [];

        while ($month <= $last) {
            foreach ($budgets as $budget) {
                $year = $years[$budget->fiscal_year_id] ?? null;

                if ($year === null || isset($used[$month->format('Ym')]) || $month->format('Y-m-d') < substr((string) $year->start_date, 0, 10) || $month->format('Y-m-d') > substr((string) $year->end_date, 0, 10)) {
                    continue;
                }

                $start = new DateTimeImmutable(substr((string) $year->start_date, 0, 7).'-01');
                $period = ((int) $month->format('Y') - (int) $start->format('Y')) * 12 + (int) $month->format('n') - (int) $start->format('n') + 1;
                $used[$month->format('Ym')] = true;

                $lines = DB::table('gl_budget_lines')->where('budget_id', $budget->id)->where('period_number', $period)->whereNull('deleted_at')
                    ->when($filter->costCenterIds !== [], fn ($q) => $q->whereIn('cost_center_id', $filter->costCenterIds))->get(['account_id', 'amount']);
                $currencyOfBudget = strtoupper((string) (DB::table('tenants')->where('id', $filter->tenantId)->value('default_currency') ?: 'EUR'));
                $rate = $this->fx->rate($filter->tenantId, $currencyOfBudget, $filter->currency, $month->format('Y-m-t')) ?? 1.0;

                foreach ($lines as $line) {
                    $account = $accounts[$line->account_id] ?? null;

                    if ($account === null) {
                        continue;
                    }

                    $category = $this->classifier->classify($account->code, $account->type, $account->reconciliation_type)->value;

                    if (isset($result[$category])) {
                        $result[$category] += (float) $line->amount * $rate;
                        $result['found'] = true;
                    }
                }
            }

            $month = $month->modify('+1 month');
        }

        return array_map(static fn (float|bool $v): float|bool => is_float($v) ? round($v, 4) : $v, $result);
    }

    /**
     * Translation effect on revenue and EBITDA: the reported figures of the range minus the same functional amounts translated
     * at the closing rate of the baseline period (constant currency).
     *
     * @return array{revenue: float, ebitda: float}
     */
    public function fxTranslation(DssFilter $filter, string $from, string $to, string $baselineEnd): array
    {
        $currency = strtolower($filter->currency);
        $rows = $this->facts($filter, $from, $to)->whereIn('category', ['revenue', 'cogs', 'opex'])
            ->selectRaw("functional_currency, category, COALESCE(SUM(amount_functional), 0) as functional, COALESCE(SUM(amount_{$currency}), 0) as reported")
            ->groupBy('functional_currency', 'category')->get();

        $impact = ['revenue' => 0.0, 'ebitda' => 0.0];

        foreach ($rows as $row) {
            $constant = (float) $row->functional * ($this->fx->rate($filter->tenantId, (string) $row->functional_currency, $filter->currency, $baselineEnd) ?? 1.0);
            // Ledger sign (debit minus credit): a larger revenue credit or a smaller cost debit both raise EBITDA, so the effect is minus the difference.
            $diff = (float) $row->reported - $constant;
            $impact['ebitda'] -= $diff;
            $impact['revenue'] -= $row->category === 'revenue' ? $diff : 0.0;
        }

        return ['revenue' => round($impact['revenue'], 4), 'ebitda' => round($impact['ebitda'], 4)];
    }

    /**
     * Share of revenue and of costs booked in a currency other than the reporting one: the part exposed to an EUR/CHF shift.
     *
     * @return array{revenue: float, cost: float}
     */
    public function foreignShares(DssFilter $filter, string $from, string $to): array
    {
        $currency = strtolower($filter->currency);
        // The currency is validated by DssFilter (CHF or EUR), so it is safe to inline.
        $flag = "CASE WHEN transaction_currency = '{$filter->currency}' THEN 0 ELSE 1 END";
        $rows = $this->facts($filter, $from, $to)->whereIn('category', ['revenue', 'cogs', 'opex'])
            ->selectRaw("category, {$flag} as foreign_flag, COALESCE(SUM(ABS(amount_{$currency})), 0) as total")
            ->groupByRaw("category, {$flag}")->get();

        $sum = ['revenue' => [0.0, 0.0], 'cost' => [0.0, 0.0]];

        foreach ($rows as $row) {
            $bucket = $row->category === 'revenue' ? 'revenue' : 'cost';
            $sum[$bucket][(int) $row->foreign_flag] += (float) $row->total;
        }

        $share = static fn (array $pair): float => ($pair[0] + $pair[1]) > 0.0 ? round($pair[1] / ($pair[0] + $pair[1]), 4) : 0.0;

        return ['revenue' => $share($sum['revenue']), 'cost' => $share($sum['cost'])];
    }

    /**
     * Full-time equivalents of the range from the approved timesheets (hours over working days x hours per day) and the
     * annualized labour cost of one FTE. Null while no timesheet exists.
     *
     * @return array{fte: float, annual_cost_per_fte: float}|null
     */
    public function fte(DssFilter $filter, string $from, string $to): ?array
    {
        $row = DB::table('fact_operational_efficiency')->where('tenant_id', $filter->tenantId)->where('source_type', 'timesheet')->whereNull('deleted_at')
            ->whereBetween('date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn ($q) => $q->whereIn('legal_entity_id', $filter->legalEntityIds))
            ->selectRaw('COALESCE(SUM(billable_hours + non_billable_hours), 0) as hours, COALESCE(SUM(labour_cost), 0) as cost')->first();
        $hours = (float) ($row->hours ?? 0.0);

        if ($hours <= 0.0) {
            return null;
        }

        $workingDays = 0;

        for ($day = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $day <= $end; $day = $day->modify('+1 day')) {
            $workingDays += (int) $day->format('N') < 6 ? 1 : 0;
        }

        $fte = $hours / (max($workingDays, 1) * (float) config('analytics_dss.working_hours_per_fte_day', 8));
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;

        return ['fte' => round($fte, 4), 'annual_cost_per_fte' => round((float) $row->cost / $fte * 365.0 / max($days, 1), 4)];
    }

    /**
     * Distinct legal entities of the tenant known to the mart (for the filter bar).
     *
     * @return list<array{id: string, name: string, currency: string}>
     */
    public function legalEntities(string $tenantId): array
    {
        return DB::table('dim_legal_entity')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('name')->get(['legal_entity_id', 'name', 'base_currency'])
            ->map(static fn (object $r): array => ['id' => (string) $r->legal_entity_id, 'name' => (string) $r->name, 'currency' => (string) $r->base_currency])->all();
    }

    /**
     * @return array{0: array<string, float>, 1: array<string, float>, 2: array<string, string>}
     */
    private function revenueByEntity(DssFilter $filter, string $dimension, string $from, string $to): array
    {
        $currency = strtolower($filter->currency);
        $revenue = [];
        $discounts = [];
        $labels = [];

        if (in_array($dimension, ['cost_center', 'legal_entity'], true)) {
            $column = $dimension === 'cost_center' ? 'cost_center_id' : 'legal_entity_id';
            $rows = $this->facts($filter, $from, $to)->where('category', 'revenue')
                ->selectRaw("COALESCE({$column}, '') as entity, COALESCE(SUM(amount_{$currency}), 0) * -1 as total")->groupByRaw("COALESCE({$column}, '')")->get();

            foreach ($rows as $row) {
                $revenue[(string) $row->entity] = round((float) $row->total, 4);
            }

            $names = $dimension === 'cost_center'
                ? DB::table('dim_cost_center')->where('tenant_id', $filter->tenantId)->pluck('name', 'cost_center_id')
                : DB::table('dim_legal_entity')->where('tenant_id', $filter->tenantId)->pluck('name', 'legal_entity_id');

            foreach (array_keys($revenue) as $key) {
                $labels[$key] = (string) ($names[$key] ?? $key);
            }

            return [$revenue, $discounts, $labels];
        }

        if ($dimension === 'product') {
            $rows = DB::table('invoice_items as i')->join('fact_commercial_velocity as v', 'v.invoice_id', '=', 'i.invoice_id')
                ->join('invoices as inv', 'inv.id', '=', 'v.invoice_id')
                ->where('v.tenant_id', $filter->tenantId)->whereNull('v.deleted_at')->whereNull('i.deleted_at')
                ->whereBetween('v.issue_date_key', [self::dateKey($from), self::dateKey($to)])
                ->when($filter->legalEntityIds !== [], fn ($q) => $q->whereIn('v.legal_entity_id', $filter->legalEntityIds))
                ->selectRaw("COALESCE(i.product_id, '') as entity, SUM(CASE WHEN inv.total_net = 0 THEN 0 ELSE i.line_total / inv.total_net * v.amount_net_{$currency} END) as total")
                ->groupByRaw("COALESCE(i.product_id, '')")->get();
            $names = DB::table('products')->where('tenant_id', $filter->tenantId)->pluck('name', 'id');

            foreach ($rows as $row) {
                $revenue[(string) $row->entity] = round((float) $row->total, 4);
                $labels[(string) $row->entity] = (string) ($names[$row->entity] ?? $row->entity);
            }

            return [$revenue, $discounts, $labels];
        }

        $column = $dimension === 'channel' ? 'channel' : 'counterparty_id';
        $rows = $this->velocity($filter, $from, $to)
            ->selectRaw("COALESCE({$column}, '') as entity, COALESCE(SUM(amount_net_{$currency}), 0) as total, COALESCE(SUM(CASE WHEN amount_net = 0 THEN 0 ELSE discount_amount / amount_net * amount_net_{$currency} END), 0) as discount")
            ->groupByRaw("COALESCE({$column}, '')")->get();
        $names = DB::table('dim_counterparty')->where('tenant_id', $filter->tenantId)->pluck('name', 'contact_id');

        foreach ($rows as $row) {
            $revenue[(string) $row->entity] = round((float) $row->total, 4);
            $discounts[(string) $row->entity] = round((float) $row->discount, 4);
            $labels[(string) $row->entity] = $dimension === 'channel' ? (string) $row->entity : (string) ($names[$row->entity] ?? $row->entity);
        }

        return [$revenue, $discounts, $labels];
    }

    /**
     * @return array<string, float>
     */
    private function productStandardCost(DssFilter $filter, string $from, string $to): array
    {
        $rate = $this->fx->rate($filter->tenantId, strtoupper((string) (DB::table('tenants')->where('id', $filter->tenantId)->value('default_currency') ?: 'EUR')), $filter->currency, $to) ?? 1.0;
        $rows = DB::table('invoice_items as i')->join('fact_commercial_velocity as v', 'v.invoice_id', '=', 'i.invoice_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('v.tenant_id', $filter->tenantId)->whereNull('v.deleted_at')->whereNull('i.deleted_at')
            ->whereBetween('v.issue_date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn ($q) => $q->whereIn('v.legal_entity_id', $filter->legalEntityIds))
            ->selectRaw('i.product_id as entity, SUM(i.quantity * p.cost_price) as total')->groupBy('i.product_id')->get();

        $cost = [];

        foreach ($rows as $row) {
            $cost[(string) $row->entity] = round((float) $row->total * $rate, 4);
        }

        return $cost;
    }

    private function facts(DssFilter $filter, string $from, string $to): Builder
    {
        return DB::table('fact_financial_transactions')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereBetween('date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn (Builder $q) => $q->whereIn('legal_entity_id', $filter->legalEntityIds))
            ->when($filter->costCenterIds !== [], fn (Builder $q) => $q->whereIn('cost_center_id', $filter->costCenterIds));
    }

    private function snapshots(DssFilter $filter, string $from, string $to): Builder
    {
        return DB::table('agg_executive_daily_snapshots')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereBetween('date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn (Builder $q) => $q->whereIn('legal_entity_id', $filter->legalEntityIds));
    }

    private function velocity(DssFilter $filter, string $from, string $to): Builder
    {
        return DB::table('fact_commercial_velocity')->where('tenant_id', $filter->tenantId)->whereNull('deleted_at')
            ->whereBetween('issue_date_key', [self::dateKey($from), self::dateKey($to)])
            ->when($filter->legalEntityIds !== [], fn (Builder $q) => $q->whereIn('legal_entity_id', $filter->legalEntityIds));
    }
}
