<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Services;

use Modules\AnalyticsDss\Domain\ValueObjects\ProfitabilityInput;

/**
 * Profitability waterfall per entity (gross margin, contribution margin I and II, EBITDA, net operating profit) and the
 * Pareto 80/20 and Whale Curve rankings built on the net operating profit. Pure: no I/O, no framework.
 */
final class ProfitabilityMatrixCalculator
{
    public function __construct(private readonly float $costOfCapitalRate = 0.06, private readonly float $paretoShare = 0.8) {}

    /**
     * @param  list<ProfitabilityInput>  $inputs
     * @return array{rows: list<array<string, mixed>>, whale_curve: list<array<string, mixed>>, totals: array<string, float>, pareto_cutoff_rank: int}
     */
    public function calculate(array $inputs): array
    {
        $rows = array_map($this->row(...), $inputs);

        // Highest net operating profit first; the key keeps equal profits in a stable order.
        usort($rows, static fn (array $a, array $b): int => [$b['net_operating_profit'], $a['key']] <=> [$a['net_operating_profit'], $b['key']]);

        $totals = $this->totals($rows);
        [$whale, $cutoff] = $this->whaleCurve($rows, $totals);

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
            $row['in_pareto_core'] = $row['net_operating_profit'] > 0.0 && $index + 1 <= $cutoff;
            $row['is_value_destroying'] = $row['net_operating_profit'] < 0.0;
        }
        unset($row);

        return ['rows' => $rows, 'whale_curve' => $whale, 'totals' => $totals, 'pareto_cutoff_rank' => $cutoff];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ProfitabilityInput $input): array
    {
        $netRevenue = $input->grossRevenue - $input->discounts;
        $grossMargin = $netRevenue - $input->cogs;
        $cm1 = $grossMargin - $input->variableCosts;
        $cm2 = $cm1 - $input->directOpex;
        $ebitda = $cm2 - $input->allocatedOverhead;
        $delayCost = $netRevenue > 0.0 ? $netRevenue * ($input->averagePaymentDelayDays / 365.0) * $this->costOfCapitalRate : 0.0;
        $netOperating = $ebitda - $input->depreciation - $delayCost;

        return [
            'key' => $input->key,
            'label' => $input->label,
            'gross_revenue' => $this->round($input->grossRevenue),
            'discounts' => $this->round($input->discounts),
            'net_revenue' => $this->round($netRevenue),
            'cogs' => $this->round($input->cogs),
            'gross_margin' => $this->round($grossMargin),
            'gross_margin_pct' => $this->pct($grossMargin, $netRevenue),
            'contribution_margin_1' => $this->round($cm1),
            'contribution_margin_2' => $this->round($cm2),
            'ebitda' => $this->round($ebitda),
            'payment_delay_cost' => $this->round($delayCost),
            'net_operating_profit' => $this->round($netOperating),
            'net_margin_pct' => $this->pct($netOperating, $netRevenue),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    private function totals(array $rows): array
    {
        $sum = static fn (string $field): float => array_sum(array_column($rows, $field));
        $netRevenue = $sum('net_revenue');

        return [
            'net_revenue' => $this->round($netRevenue),
            'gross_margin' => $this->round($sum('gross_margin')),
            'contribution_margin_1' => $this->round($sum('contribution_margin_1')),
            'contribution_margin_2' => $this->round($sum('contribution_margin_2')),
            'ebitda' => $this->round($sum('ebitda')),
            'net_operating_profit' => $this->round($sum('net_operating_profit')),
            'net_margin_pct' => $this->pct($sum('net_operating_profit'), $netRevenue),
        ];
    }

    /**
     * Cumulative net profit by descending rank: the curve rises above 100% of the total profit while profitable entities are
     * added and falls back to 100% as the value destroying tail is included (the "whale").
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, float>  $totals
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function whaleCurve(array $rows, array $totals): array
    {
        $totalProfit = $totals['net_operating_profit'];
        $totalRevenue = max($totals['net_revenue'], 0.0);
        $positiveProfit = array_sum(array_map(static fn (array $r): float => max((float) $r['net_operating_profit'], 0.0), $rows));

        $curve = [];
        $cumulative = 0.0;
        $cumulativeRevenue = 0.0;
        $cutoff = 0;

        foreach ($rows as $index => $row) {
            $cumulative += (float) $row['net_operating_profit'];
            $cumulativeRevenue += max((float) $row['net_revenue'], 0.0);
            $positiveCumulative = array_sum(array_map(static fn (array $r): float => max((float) $r['net_operating_profit'], 0.0), array_slice($rows, 0, $index + 1)));

            if ($cutoff === 0 && $positiveProfit > 0.0 && $positiveCumulative >= $positiveProfit * $this->paretoShare) {
                $cutoff = $index + 1;
            }

            $curve[] = [
                'rank' => $index + 1,
                'key' => $row['key'],
                'label' => $row['label'],
                'net_operating_profit' => $row['net_operating_profit'],
                'cumulative_profit' => $this->round($cumulative),
                'cumulative_profit_pct' => $totalProfit !== 0.0 ? $this->round($cumulative / $totalProfit * 100.0) : 0.0,
                'cumulative_revenue_pct' => $totalRevenue > 0.0 ? $this->round($cumulativeRevenue / $totalRevenue * 100.0) : 0.0,
                'entity_pct' => $this->round(($index + 1) / count($rows) * 100.0),
            ];
        }

        return [$curve, $cutoff];
    }

    private function pct(float $part, float $whole): float
    {
        return $whole != 0.0 ? $this->round($part / $whole * 100.0) : 0.0;
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }
}
