<?php

declare(strict_types=1);

namespace Modules\Controlling\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Controlling\Support\Decimal;

/**
 * Multidimensional margin control (#216): the margin waterfall of the sales facts by product, product family, customer,
 * geography, agent, production department, cost center and job, with drill-down, comparison baselines, ABC / Pareto
 * classes and cross-tabs.
 *
 * Net revenue = gross revenue - discounts - credit notes; first contribution margin = net revenue - direct COGS; second
 * (net commercial) = first - commissions - logistics; full industrial = second - allocated overheads.
 */
final class MultidimensionalMarginService
{
    public const array Dimensions = ['product', 'product_family', 'customer', 'geography', 'agent', 'production_department', 'cost_center', 'job'];

    public const array Tiers = ['gross', 'commercial', 'full_industrial'];

    public const array CompareModes = ['none', 'prior_period', 'prior_year', 'active_budget', 'rolling_forecast'];

    /** Filter name => fact column. */
    public const array FilterColumns = [
        'product_id' => 'product_id', 'product_family_id' => 'product_family_id', 'customer_id' => 'customer_id', 'country_code' => 'country_code',
        'region_code' => 'region_code', 'province_code' => 'province_code', 'sales_agent_id' => 'sales_agent_id',
        'production_department_id' => 'production_department_id', 'cost_center_id' => 'cost_center_id', 'job_id' => 'job_id',
    ];

    private const array GeoLevels = ['country_code', 'region_code', 'province_code'];

    private const array Measures = ['net_revenue', 'gross_margin', 'commercial_margin', 'full_industrial_margin'];

    public function __construct(private readonly BudgetVarianceService $budgets) {}

    /**
     * Totals of the period and the deltas against the baseline.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function summary(string $tenantId, array $query): array
    {
        [$from, $to, $tier, $filters, $mode] = $this->context($query);
        $totals = $this->metrics($this->aggregate($tenantId, $from, $to, [], $filters)[0] ?? [], $tier);
        $result = ['date_from' => $from, 'date_to' => $to, 'margin_tier' => $tier, 'compare_mode' => $mode, 'totals' => $totals, 'baseline' => null, 'deltas' => null];
        if ($mode !== 'none') {
            $baseline = $this->baseline($tenantId, $from, $to, $mode, [], $filters, $tier);
            $base = $baseline[''] ?? $this->metrics([], $tier);
            $result['baseline'] = $base;
            $result['deltas'] = $this->deltas($totals, $base);
        }

        return $result;
    }

    /**
     * Rows of one grouping dimension, sorted and paginated, after the drill path.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function explore(string $tenantId, array $query): array
    {
        [$from, $to, $tier, $filters, $mode] = $this->context($query);
        $groupBy = (string) ($query['group_by'] ?? 'product');
        $drill = $this->applyDrill((array) ($query['drill_path'] ?? []), $filters);
        $filters = $drill['filters'];
        $columns = $this->groupColumns($groupBy, $drill['geo_depth']);
        $rows = $this->rows($tenantId, $from, $to, $columns, $filters, $tier);
        $rows = $this->classify($rows, $tier);

        if ($mode !== 'none') {
            $baseline = $this->baseline($tenantId, $from, $to, $mode, $columns, $filters, $tier);
            foreach ($rows as &$row) {
                $base = $baseline[$row['key']] ?? null;
                $row['baseline'] = $base;
                $row['deltas'] = $base === null ? null : $this->deltas($row['metrics'], $base);
            }
            unset($row);
        }

        $sort = (string) ($query['sort'] ?? 'net_revenue');
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 1 : -1;
        usort($rows, static fn (array $a, array $b): int => $direction * ((float) ($a['metrics'][$sort] ?? 0) <=> (float) ($b['metrics'][$sort] ?? 0)) ?: strcmp((string) $a['key'], (string) $b['key']));

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(500, (int) ($query['per_page'] ?? 50)));
        $totals = $this->metrics($this->aggregate($tenantId, $from, $to, [], $filters)[0] ?? [], $tier);

        return [
            'group_by' => $groupBy, 'margin_tier' => $tier, 'date_from' => $from, 'date_to' => $to, 'compare_mode' => $mode,
            'drill_path' => $drill['path'], 'next_dimensions' => $this->nextDimensions($groupBy, $drill['geo_depth']),
            'totals' => $totals, 'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'meta' => ['total' => count($rows), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil(count($rows) / $perPage))],
        ];
    }

    /**
     * Two-dimensional cross-tab.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function matrix(string $tenantId, array $query): array
    {
        [$from, $to, $tier, $filters] = $this->context($query);
        $rowDim = (string) ($query['row_dimension'] ?? 'product_family');
        $colDim = (string) ($query['col_dimension'] ?? 'customer');
        $measure = in_array($query['measure'] ?? null, [...self::Measures, 'margin_percent'], true) ? (string) $query['measure'] : 'margin_percent';
        $rowCols = $this->groupColumns($rowDim, 0);
        $colCols = $this->groupColumns($colDim, 0);
        $raw = $this->aggregate($tenantId, $from, $to, array_merge($rowCols, $colCols), $filters);
        $labels = [$rowDim => [], $colDim => []];
        $cells = [];
        $rowTotals = [];
        $colTotals = [];
        foreach ($raw as $record) {
            $rowKey = $this->keyOf($record, $rowCols);
            $colKey = $this->keyOf($record, $colCols);
            $metrics = $this->metrics($record, $tier);
            $cells[$rowKey][$colKey] = $metrics;
            $labels[$rowDim][$rowKey] = true;
            $labels[$colDim][$colKey] = true;
        }
        $rowLabels = $this->labels($tenantId, $rowDim, array_keys($labels[$rowDim]));
        $colLabels = $this->labels($tenantId, $colDim, array_keys($labels[$colDim]));
        $out = [];
        foreach ($cells as $rowKey => $byCol) {
            foreach ($byCol as $colKey => $metrics) {
                $out[] = ['row' => (string) $rowKey, 'col' => (string) $colKey, 'value' => $metrics[$measure], 'metrics' => $metrics];
            }
        }

        return [
            'row_dimension' => $rowDim, 'col_dimension' => $colDim, 'measure' => $measure, 'margin_tier' => $tier,
            'rows' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $rowLabels[$k] ?? $k], array_map('strval', array_keys($labels[$rowDim]))),
            'columns' => array_map(static fn (string $k): array => ['key' => $k, 'label' => $colLabels[$k] ?? $k], array_map('strval', array_keys($labels[$colDim]))),
            'cells' => $out,
        ];
    }

    /**
     * ABC (80 / 15 / 5) classification and the entities that erode the margin.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function pareto(string $tenantId, array $query): array
    {
        [$from, $to, $tier, $filters] = $this->context($query);
        $groupBy = (string) ($query['group_by'] ?? 'product');
        $columns = $this->groupColumns($groupBy, 0);
        $rows = $this->classify($this->rows($tenantId, $from, $to, $columns, $filters, $tier), $tier);
        $totals = $this->metrics($this->aggregate($tenantId, $from, $to, [], $filters)[0] ?? [], $tier);
        $overall = (float) $totals['margin_percent'];
        $outliers = array_values(array_filter($rows, static fn (array $r): bool => (float) $r['metrics']['net_revenue'] > 0 && ((float) $r['metrics']['margin_percent'] < 0 || (float) $r['metrics']['margin_percent'] < $overall / 2)));
        $classTotals = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($rows as $row) {
            $classTotals[$row['abc_class']]++;
        }

        return [
            'group_by' => $groupBy, 'margin_tier' => $tier, 'totals' => $totals, 'class_counts' => $classTotals,
            'curve' => array_map(static fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'net_revenue' => $r['metrics']['net_revenue'], 'cumulative_share' => $r['cumulative_share'], 'abc_class' => $r['abc_class']], $rows),
            'outliers' => array_map(static fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'net_revenue' => $r['metrics']['net_revenue'], 'margin' => $r['metrics']['margin'], 'margin_percent' => $r['metrics']['margin_percent']], $outliers),
        ];
    }

    /**
     * Flat rows for the CSV / XLSX export.
     *
     * @param  array<string, mixed>  $query
     * @return list<list<string>>
     */
    public function exportRows(string $tenantId, array $query): array
    {
        $result = $this->explore($tenantId, ['per_page' => 500] + $query);
        $out = [];
        foreach ($result['data'] as $row) {
            $m = $row['metrics'];
            $out[] = [$row['label'], $m['quantity'], $m['gross_revenue'], $m['discounts'], $m['credit_notes'], $m['net_revenue'], $m['direct_cogs'], $m['allocated_overheads'], $m['gross_margin'], $m['gross_margin_percent'], $m['commercial_margin'], $m['full_industrial_margin'], $m['margin_percent'], $row['abc_class']];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function exportHeader(): array
    {
        return ['Entity', 'Quantity', 'Gross revenue', 'Discounts', 'Credit notes', 'Net revenue', 'Direct COGS', 'Allocated overheads', 'Gross margin', 'Gross margin %', 'Net commercial margin', 'Full industrial margin', 'Margin % (selected tier)', 'ABC class'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{0: string, 1: string, 2: string, 3: array<string, string>, 4: string}
     */
    private function context(array $query): array
    {
        $from = (string) ($query['date_from'] ?? CarbonImmutable::now()->startOfYear()->toDateString());
        $to = (string) ($query['date_to'] ?? CarbonImmutable::now()->toDateString());
        $tier = (string) ($query['margin_tier'] ?? 'gross');
        $mode = (string) ($query['compare_mode'] ?? 'none');
        if (! in_array($tier, self::Tiers, true) || ! in_array($mode, self::CompareModes, true)) {
            throw new InvalidArgumentException('Unknown margin tier or comparison mode.');
        }
        $filters = [];
        foreach (self::FilterColumns as $name => $column) {
            if (isset($query[$name]) && $query[$name] !== '') {
                $filters[$column] = (string) $query[$name];
            }
        }

        return [$from, $to, $tier, $filters, $mode];
    }

    /**
     * @param  list<array{dimension?: string, value?: string}>  $path
     * @param  array<string, string>  $filters
     * @return array{filters: array<string, string>, geo_depth: int, path: list<array{dimension: string, value: string}>}
     */
    private function applyDrill(array $path, array $filters): array
    {
        $geoDepth = 0;
        $clean = [];
        foreach ($path as $step) {
            $dimension = (string) ($step['dimension'] ?? '');
            $value = (string) ($step['value'] ?? '');
            if (! in_array($dimension, self::Dimensions, true) || $value === '') {
                continue;
            }
            if ($dimension === 'geography') {
                $filters[self::GeoLevels[min($geoDepth, 2)]] = $value;
                $geoDepth = min($geoDepth + 1, 2);
            } else {
                $filters[$this->groupColumns($dimension, 0)[0]] = $value;
            }
            $clean[] = ['dimension' => $dimension, 'value' => $value];
        }

        return ['filters' => $filters, 'geo_depth' => $geoDepth, 'path' => $clean];
    }

    /**
     * @return list<string>
     */
    private function nextDimensions(string $groupBy, int $geoDepth): array
    {
        $others = array_values(array_filter(self::Dimensions, static fn (string $d): bool => $d !== $groupBy));

        return $groupBy === 'geography' && $geoDepth < 2 ? ['geography', ...$others] : $others;
    }

    /**
     * @return list<string>
     */
    private function groupColumns(string $dimension, int $geoDepth): array
    {
        return match ($dimension) {
            'product' => ['product_id'], 'product_family' => ['product_family_id'], 'customer' => ['customer_id'],
            'geography' => [self::GeoLevels[min($geoDepth, 2)]], 'agent' => ['sales_agent_id'],
            'production_department' => ['production_department_id'], 'cost_center' => ['cost_center_id'], 'job' => ['job_id'],
            default => throw new InvalidArgumentException("Unknown dimension {$dimension}."),
        };
    }

    /**
     * Rows of the period grouped by the columns, as raw string aggregates.
     *
     * @param  list<string>  $groupColumns
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function aggregate(string $tenantId, string $from, string $to, array $groupColumns, array $filters): array
    {
        $query = DB::table('bi_sales_margin_facts')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereBetween('posting_date', [$from, $to]);
        foreach ($filters as $column => $value) {
            if ($column === 'cost_center_id') {
                $query->whereIn('cost_center_id', $this->costCenterSubtree($tenantId, $value));
            } else {
                $query->where($column, $value);
            }
        }
        $select = [
            "SUM(CASE WHEN document_type <> 'credit_note' THEN gross_revenue ELSE 0 END) AS gross_revenue",
            "SUM(CASE WHEN document_type <> 'credit_note' THEN discount_amount ELSE 0 END) AS discounts",
            "SUM(CASE WHEN document_type = 'credit_note' THEN -net_revenue ELSE 0 END) AS credit_notes",
            'SUM(net_revenue) AS net_revenue', 'SUM(quantity_sold) AS quantity', 'SUM(direct_cogs_amount) AS direct_cogs',
            'SUM(allocated_overhead_amount) AS allocated_overheads', 'SUM(commission_amount) AS commissions', 'SUM(logistics_cost_amount) AS logistics',
            'SUM(first_contribution_margin) AS gross_margin', 'SUM(second_contribution_margin) AS commercial_margin', 'SUM(full_industrial_margin) AS full_industrial_margin',
        ];
        foreach ($groupColumns as $column) {
            $select[] = $column;
            $query->groupBy($column);
        }

        return array_map(static fn (object $row): array => (array) $row, $query->selectRaw(implode(', ', $select))->get()->all());
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters
     * @return list<array<string, mixed>>
     */
    private function rows(string $tenantId, string $from, string $to, array $columns, array $filters, string $tier): array
    {
        $raw = $this->aggregate($tenantId, $from, $to, $columns, $filters);
        $dimension = $this->dimensionOf($columns[0]);
        $labels = $this->labels($tenantId, $dimension, array_map(fn (array $r): string => $this->keyOf($r, $columns), $raw));
        $rows = [];
        foreach ($raw as $record) {
            $key = $this->keyOf($record, $columns);
            $rows[] = ['key' => $key, 'label' => $labels[$key] ?? ($key === '' ? '—' : $key), 'dimension' => $dimension, 'metrics' => $this->metrics($record, $tier)];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $columns
     */
    private function keyOf(array $record, array $columns): string
    {
        return implode('|', array_map(static fn (string $c): string => (string) ($record[$c] ?? ''), $columns));
    }

    private function dimensionOf(string $column): string
    {
        return match ($column) {
            'product_id' => 'product', 'product_family_id' => 'product_family', 'customer_id' => 'customer', 'sales_agent_id' => 'agent',
            'production_department_id' => 'production_department', 'cost_center_id' => 'cost_center', 'job_id' => 'job', default => 'geography',
        };
    }

    /**
     * Every monetary and ratio figure of a record, as 4-decimal strings (percentages with 2).
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function metrics(array $record, string $tier): array
    {
        $v = static fn (string $k): string => Decimal::norm(number_format((float) ($record[$k] ?? 0), 4, '.', ''));
        $net = $v('net_revenue');
        $gross = $v('gross_revenue');
        $margin = match ($tier) {
            'commercial' => $v('commercial_margin'), 'full_industrial' => $v('full_industrial_margin'), default => $v('gross_margin'),
        };
        $quantity = $v('quantity');

        return [
            'quantity' => $quantity, 'gross_revenue' => $gross, 'discounts' => $v('discounts'), 'credit_notes' => $v('credit_notes'), 'net_revenue' => $net,
            'direct_cogs' => $v('direct_cogs'), 'allocated_overheads' => $v('allocated_overheads'), 'commissions' => $v('commissions'), 'logistics' => $v('logistics'),
            'gross_margin' => $v('gross_margin'), 'gross_margin_percent' => Decimal::percent($v('gross_margin'), $net),
            'commercial_margin' => $v('commercial_margin'), 'full_industrial_margin' => $v('full_industrial_margin'),
            'margin' => $margin, 'margin_percent' => Decimal::percent($margin, $net),
            'credit_note_incidence_percent' => Decimal::percent($v('credit_notes'), $gross),
            'average_selling_price' => Decimal::round(Decimal::div($net, $quantity)), 'unit_cost' => Decimal::round(Decimal::div($v('direct_cogs'), $quantity)),
            'unit_margin' => Decimal::round(Decimal::div($v('gross_margin'), $quantity)),
        ];
    }

    /**
     * Rank by net revenue and add the cumulative share and the ABC class (A: up to 80%, B: next 15%, C: last 5%).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function classify(array $rows, string $tier): array
    {
        usort($rows, static fn (array $a, array $b): int => ((float) $b['metrics']['net_revenue'] <=> (float) $a['metrics']['net_revenue']) ?: strcmp((string) $a['key'], (string) $b['key']));
        $total = array_sum(array_map(static fn (array $r): float => max(0.0, (float) $r['metrics']['net_revenue']), $rows));
        $cumulative = 0.0;
        foreach ($rows as &$row) {
            $before = $total > 0 ? $cumulative / $total * 100 : 100.0;
            $cumulative += max(0.0, (float) $row['metrics']['net_revenue']);
            $row['cumulative_share'] = number_format($total > 0 ? $cumulative / $total * 100 : 100.0, 2, '.', '');
            $row['abc_class'] = $before < 80 ? 'A' : ($before < 95 ? 'B' : 'C');
        }
        unset($row);

        return $rows;
    }

    /**
     * @param  array<string, string>  $current
     * @param  array<string, string>  $base
     * @return array<string, array{delta: string, delta_percent: string}>
     */
    private function deltas(array $current, array $base): array
    {
        $out = [];
        foreach (['net_revenue', 'gross_margin', 'commercial_margin', 'full_industrial_margin', 'margin', 'direct_cogs', 'allocated_overheads'] as $key) {
            $delta = Decimal::sub($current[$key] ?? 0, $base[$key] ?? 0);
            $out[$key] = ['delta' => $delta, 'delta_percent' => Decimal::percent($delta, Decimal::abs($base[$key] ?? 0))];
        }
        $out['margin_percent'] = ['delta' => Decimal::sub($current['margin_percent'], $base['margin_percent'] ?? 0), 'delta_percent' => '0.00'];

        return $out;
    }

    /**
     * Baseline metrics by group key.
     *
     * @param  list<string>  $columns
     * @param  array<string, string>  $filters
     * @return array<string, array<string, string>>
     */
    private function baseline(string $tenantId, string $from, string $to, string $mode, array $columns, array $filters, string $tier): array
    {
        if ($mode === 'prior_period' || $mode === 'prior_year') {
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);
            if ($mode === 'prior_year') {
                [$start, $end] = [$start->subYear(), $end->subYear()];
            } else {
                $days = (int) $start->diffInDays($end) + 1;
                [$start, $end] = [$start->subDays($days), $start->subDay()];
            }
            $out = [];
            foreach ($this->aggregate($tenantId, $start->toDateString(), $end->toDateString(), $columns, $filters) as $record) {
                $out[$this->keyOf($record, $columns)] = $this->metrics($record, $tier);
            }

            return $out;
        }

        $planType = $mode === 'rolling_forecast' ? 'rolling_forecast' : 'annual_budget';
        $planId = $this->budgets->activePlanId($tenantId, (int) substr($from, 0, 4), $planType);
        if ($planId === null) {
            return [];
        }
        $months = range((int) substr($from, 5, 2), substr($to, 0, 4) === substr($from, 0, 4) ? (int) substr($to, 5, 2) : 12);
        $column = $columns[0] ?? null;
        $planColumn = $column === 'product_id' ? null : $column;
        if ($column === 'product_id') {
            return [];
        }
        $out = [];
        foreach ($this->budgets->planTotals($planId, $months, $planColumn, $filters) as $key => $totals) {
            $m = $this->metrics(['net_revenue' => $totals['revenue'], 'gross_margin' => $totals['margin'], 'commercial_margin' => $totals['margin'], 'full_industrial_margin' => $totals['margin']], $tier);
            $out[$key === '' && $column !== null ? '' : $key] = $m;
        }

        return $out;
    }

    /**
     * Labels of the group keys.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function labels(string $tenantId, string $dimension, array $keys): array
    {
        $keys = array_values(array_filter(array_unique($keys), static fn (string $k): bool => $k !== ''));
        if ($keys === []) {
            return [];
        }
        $named = static fn (string $table, string $expr): array => DB::table($table)->whereIn('id', $keys)->selectRaw("id, {$expr} AS label")->pluck('label', 'id')->map(static fn ($l): string => (string) $l)->all();

        return match ($dimension) {
            'product' => $named('products', "sku || ' - ' || name"),
            'product_family' => $named('product_categories', 'name'),
            'customer' => $named('contacts', "COALESCE(NULLIF(company_name, ''), TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')))"),
            'agent' => $named('sales_representatives', 'name'),
            'cost_center', 'production_department' => $named('analytical_cost_centers', "code || ' - ' || name"),
            'job' => $named('analytical_jobs', "code || ' - ' || name"),
            default => array_combine($keys, $keys),
        };
    }

    /**
     * The center and all its descendants.
     *
     * @return list<string>
     */
    public function costCenterSubtree(string $tenantId, string $rootId): array
    {
        $parents = DB::table('analytical_cost_centers')->where('tenant_id', $tenantId)->whereNull('deleted_at')->pluck('parent_id', 'id')->all();
        $ids = [$rootId];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($parents as $id => $parent) {
                if ($parent !== null && in_array($parent, $ids, true) && ! in_array($id, $ids, true)) {
                    $ids[] = (string) $id;
                    $changed = true;
                }
            }
        }

        return $ids;
    }
}
