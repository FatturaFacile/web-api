<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Application\Handlers;

use Modules\AnalyticsDss\Application\Queries\GetExecutiveDssOverviewQuery;
use Modules\AnalyticsDss\Domain\Services\ExecutiveAnomalyDetector;
use Modules\AnalyticsDss\Domain\ValueObjects\DssFilter;
use Modules\AnalyticsDss\Infrastructure\Projection\MartReader;
use Modules\AnalyticsDss\Infrastructure\Support\DssCache;
use Modules\AnalyticsDss\Infrastructure\Support\FxRateResolver;

/**
 * Balanced Scorecard of the executive cockpit: financial, customer and efficiency KPIs with period comparison and monthly
 * sparklines, plus the active strategic alerts.
 */
final class GetExecutiveDssOverviewHandler
{
    public function __construct(
        private readonly ExecutiveFigures $figures,
        private readonly MartReader $reader,
        private readonly FxRateResolver $fx,
        private readonly DssCache $cache,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetExecutiveDssOverviewQuery $query): array
    {
        $filter = $query->filter;

        return $this->cache->remember($filter->tenantId, $filter->cacheKey('overview'), fn (): array => $this->build($filter));
    }

    /**
     * @return array<string, mixed>
     */
    private function build(DssFilter $filter): array
    {
        $current = $this->figures->period($filter, $filter->from, $filter->to);
        [$priorFrom, $priorTo] = $filter->comparisonRange();
        $isBudget = $filter->comparisonBaseline === 'BUDGET';
        $baseline = $isBudget ? $this->figures->budget($filter) : $this->figures->period($filter, $priorFrom, $priorTo);

        // Customer retention always compares with a real period: the prior year when the baseline is the budget.
        $retentionFrom = $isBudget ? (new \DateTimeImmutable($filter->from))->modify('-1 year')->format('Y-m-d') : $priorFrom;
        $retentionTo = $isBudget ? (new \DateTimeImmutable($filter->to))->modify('-1 year')->format('Y-m-d') : $priorTo;

        $commercial = $this->reader->commercial($filter, $filter->from, $filter->to);
        $priorCommercial = $this->reader->commercial($filter, $retentionFrom, $retentionTo);
        $operations = $this->reader->operations($filter, $filter->from, $filter->to);
        $fte = $this->reader->fte($filter, $filter->from, $filter->to);
        $series = $this->reader->monthlySeries($filter, (new \DateTimeImmutable($filter->to))->modify('-11 months')->format('Y-m-01'), $filter->to);

        $concentration = $this->concentration($commercial['customers']);
        $retention = $this->retention($commercial['customers'], $priorCommercial['customers']);
        $wc = $current['working_capital'];
        $priorWc = $baseline['working_capital'];
        $turnover = $current['balances']['inventory'] > 0.0 ? round($current['cogs'] * 365.0 / max($current['days'], 1) / $current['balances']['inventory'], 2) : null;

        $kpi = fn (string $group, string $key, string $unit, ?float $value, ?float $comparison, array $spark = [], bool $lowerIsBetter = false): array => $this->kpi($group, $key, $unit, $value, $comparison, $spark, $lowerIsBetter);
        $sparkOf = static fn (string $field): array => array_values(array_map(static fn (array $m): float => round($m[$field], 2), $series));
        $marginSpark = array_values(array_map(static fn (array $m): float => $m['revenue'] != 0.0 ? round(($m['revenue'] - $m['cogs']) / $m['revenue'] * 100.0, 2) : 0.0, $series));
        $ebitdaSpark = array_values(array_map(static fn (array $m): float => round($m['revenue'] - $m['cogs'] - $m['opex'], 2), $series));

        $scorecard = [
            'financial' => [
                $kpi('financial', 'revenue', 'currency', $current['revenue'], $baseline['revenue'], $sparkOf('revenue')),
                $kpi('financial', 'gross_margin_pct', 'percent', $current['gross_margin_pct'], $baseline['gross_margin_pct'], $marginSpark),
                $kpi('financial', 'ebitda', 'currency', $current['ebitda'], $baseline['ebitda'], $ebitdaSpark),
                $kpi('financial', 'net_cash_flow', 'currency', $current['net_cash_flow'], $isBudget ? null : $baseline['net_cash_flow'], $sparkOf('cash')),
                $kpi('financial', 'cash_conversion_cycle', 'days', $wc['ccc_days'], $priorWc['ccc_days'] ?? null, [], true),
            ],
            'customer' => [
                $kpi('customer', 'net_revenue_retention', 'percent', $retention, null),
                $kpi('customer', 'customer_concentration_index', 'percent', $concentration['share'], null, [], true),
                $kpi('customer', 'quote_win_rate', 'percent', $commercial['win_rate_pct'], $priorCommercial['win_rate_pct']),
                $kpi('customer', 'average_dso', 'days', $wc['dso_days'], $priorWc['dso_days'] ?? null, [], true),
            ],
            'efficiency' => [
                $kpi('efficiency', 'operating_expense_ratio', 'percent', $current['revenue'] != 0.0 ? round($current['opex'] / $current['revenue'] * 100.0, 2) : null, $baseline['revenue'] != 0.0 ? round($baseline['opex'] / $baseline['revenue'] * 100.0, 2) : null, [], true),
                $kpi('efficiency', 'revenue_per_fte', 'currency', $fte !== null && $fte['fte'] > 0.0 ? round($current['revenue'] / $fte['fte'], 2) : null, null),
                $kpi('efficiency', 'inventory_turnover', 'ratio', $turnover, null),
                $kpi('efficiency', 'on_time_delivery_pct', 'percent', null, null),
            ],
        ];

        $budgetLines = [];

        if ($isBudget && ($baseline['budget_found'] ?? false)) {
            $budgetLines = [
                'cogs' => ['budget' => $baseline['cogs'], 'actual' => $current['cogs'], 'is_cost' => true],
                'opex' => ['budget' => $baseline['opex'], 'actual' => $current['opex'], 'is_cost' => true],
            ];
        }

        $alerts = (new ExecutiveAnomalyDetector((array) config('analytics_dss.anomaly', [])))->detect([
            'gross_margin_pct' => $current['gross_margin_pct'],
            'prior_gross_margin_pct' => $isBudget ? null : $baseline['gross_margin_pct'],
            'dso_days' => (float) $wc['dso_days'],
            'prior_dso_days' => $isBudget ? null : (float) $priorWc['dso_days'],
            'customer_revenue' => $commercial['customers'],
            'budget_lines' => $budgetLines,
            'series' => ['revenue' => $sparkOf('revenue'), 'ebitda' => $ebitdaSpark, 'net_cash_flow' => $sparkOf('cash')],
        ]);

        return [
            'meta' => [
                'currency' => $filter->currency,
                'period' => ['preset' => $filter->periodPreset, 'from' => $filter->from, 'to' => $filter->to],
                'comparison' => ['baseline' => $filter->comparisonBaseline, 'from' => $priorFrom, 'to' => $priorTo, 'budget_found' => $isBudget ? (bool) ($baseline['budget_found'] ?? false) : null],
                'fx' => ['CHF_EUR' => $this->fx->rate($filter->tenantId, 'CHF', 'EUR', $filter->to), 'EUR_CHF' => $this->fx->rate($filter->tenantId, 'EUR', 'CHF', $filter->to), 'as_of' => $filter->to],
                'legal_entities' => $filter->legalEntityIds,
            ],
            'scorecard' => $scorecard,
            'alerts' => $alerts,
            'working_capital' => $wc,
            'operations' => $operations,
        ];
    }

    /**
     * @param  list<float>  $spark
     * @return array<string, mixed>
     */
    private function kpi(string $group, string $key, string $unit, ?float $value, ?float $comparison, array $spark, bool $lowerIsBetter): array
    {
        $delta = $value !== null && $comparison !== null ? round($value - $comparison, 4) : null;

        return [
            'key' => $key, 'group' => $group, 'unit' => $unit, 'available' => $value !== null, 'value' => $value !== null ? round($value, 4) : null,
            'comparison_value' => $comparison !== null ? round($comparison, 4) : null, 'delta' => $delta,
            'delta_pct' => $delta !== null && $comparison != 0.0 ? round($delta / abs($comparison) * 100.0, 2) : null,
            'favorable' => $delta === null ? null : ($lowerIsBetter ? $delta <= 0.0 : $delta >= 0.0),
            'sparkline' => $spark, 'target' => null,
        ];
    }

    /**
     * @param  array<string, array{label: string, revenue: float}>  $customers
     * @return array{share: float|null, counterparty_id: string|null}
     */
    private function concentration(array $customers): array
    {
        $total = array_sum(array_column($customers, 'revenue'));

        if ($total <= 0.0) {
            return ['share' => null, 'counterparty_id' => null];
        }

        uasort($customers, static fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);
        $topKey = (string) array_key_first($customers);

        return ['share' => round($customers[$topKey]['revenue'] / $total * 100.0, 2), 'counterparty_id' => $topKey !== '' ? $topKey : null];
    }

    /**
     * Revenue of today from the customers of the prior period over the revenue of the prior period, in percent.
     *
     * @param  array<string, array{label: string, revenue: float}>  $current
     * @param  array<string, array{label: string, revenue: float}>  $prior
     */
    private function retention(array $current, array $prior): ?float
    {
        $base = array_sum(array_column($prior, 'revenue'));

        if ($base <= 0.0) {
            return null;
        }

        $kept = array_sum(array_map(static fn (string $key): float => $current[$key]['revenue'] ?? 0.0, array_keys($prior)));

        return round($kept / $base * 100.0, 2);
    }
}
