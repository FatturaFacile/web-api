<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Services;

/**
 * Deterministic sensitivity engine. The desktop (`WhatIfScenarioCalculator`) and mobile clients implement the same
 * formulas offline, so any change here must be mirrored there:
 *
 *   revenue'  = revenue  x (1 + price%)  x (1 + fx% x revenueFxShare)
 *   cogs'     = cogs     x (1 + cogs%)   x (1 + fx% x costFxShare)
 *   opex'     = (opex x (1 + opex%) + fteDelta x fteAnnualCost x periodDays/365) x (1 + fx% x costFxShare)
 *   EBITDA'   = revenue' - cogs' - opex'
 *   netCash'  = netCash + (EBITDA' - EBITDA) - (receivables' - receivables),  receivables = revenue x DSO / periodDays
 *   breakEven = opex' / ((revenue' - cogs') / revenue')    (null while the contribution margin is not positive)
 */
final class WhatIfScenarioSimulator
{
    /**
     * @param  array{revenue: float, cogs: float, opex: float, dso_days: float, net_cash_flow: float, period_days: int, fte_annual_cost?: float, revenue_fx_share?: float, cost_fx_share?: float}  $baseline
     * @param  array{price_pct?: float, cogs_pct?: float, opex_pct?: float, dso_days_delta?: float, fx_shift_pct?: float, fte_delta?: float}  $scenario  Percentages are whole numbers (5 = +5%).
     * @return array{baseline: array<string, float|null>, simulated: array<string, float|null>, delta: array<string, float|null>}
     */
    public function simulate(array $baseline, array $scenario): array
    {
        $days = max((int) $baseline['period_days'], 1);
        $fteCost = (float) ($baseline['fte_annual_cost'] ?? 0.0);
        $revShare = (float) ($baseline['revenue_fx_share'] ?? 0.0);
        $costShare = (float) ($baseline['cost_fx_share'] ?? 0.0);

        $price = ($scenario['price_pct'] ?? 0.0) / 100.0;
        $cogsPct = ($scenario['cogs_pct'] ?? 0.0) / 100.0;
        $opexPct = ($scenario['opex_pct'] ?? 0.0) / 100.0;
        $fx = ($scenario['fx_shift_pct'] ?? 0.0) / 100.0;
        $dsoDelta = (float) ($scenario['dso_days_delta'] ?? 0.0);
        $fteDelta = (float) ($scenario['fte_delta'] ?? 0.0);

        $revenue = (float) $baseline['revenue'];
        $cogs = (float) $baseline['cogs'];
        $opex = (float) $baseline['opex'];
        $dso = (float) $baseline['dso_days'];

        $revenueSim = $revenue * (1 + $price) * (1 + $fx * $revShare);
        $cogsSim = $cogs * (1 + $cogsPct) * (1 + $fx * $costShare);
        $opexSim = ($opex * (1 + $opexPct) + $fteDelta * $fteCost * $days / 365.0) * (1 + $fx * $costShare);

        $ebitda = $revenue - $cogs - $opex;
        $ebitdaSim = $revenueSim - $cogsSim - $opexSim;

        $receivables = $revenue * $dso / $days;
        $receivablesSim = $revenueSim * max($dso + $dsoDelta, 0.0) / $days;
        $netCash = (float) $baseline['net_cash_flow'];
        $netCashSim = $netCash + ($ebitdaSim - $ebitda) - ($receivablesSim - $receivables);

        $base = $this->figures($revenue, $cogs, $opex, $ebitda, $netCash);
        $sim = $this->figures($revenueSim, $cogsSim, $opexSim, $ebitdaSim, $netCashSim);

        return [
            'baseline' => $base,
            'simulated' => $sim,
            'delta' => [
                'revenue' => round($sim['revenue'] - $base['revenue'], 4),
                'ebitda' => round($sim['ebitda'] - $base['ebitda'], 4),
                'ebitda_margin_pct' => round((float) $sim['ebitda_margin_pct'] - (float) $base['ebitda_margin_pct'], 2),
                'net_cash_flow' => round($sim['net_cash_flow'] - $base['net_cash_flow'], 4),
                'break_even_revenue' => $sim['break_even_revenue'] !== null && $base['break_even_revenue'] !== null
                    ? round($sim['break_even_revenue'] - $base['break_even_revenue'], 4) : null,
            ],
        ];
    }

    /**
     * @return array{revenue: float, cogs: float, opex: float, ebitda: float, ebitda_margin_pct: float, net_cash_flow: float, break_even_revenue: float|null, break_even_headroom_pct: float|null}
     */
    private function figures(float $revenue, float $cogs, float $opex, float $ebitda, float $netCash): array
    {
        $contributionRatio = $revenue > 0.0 ? ($revenue - $cogs) / $revenue : 0.0;
        $breakEven = $contributionRatio > 0.0 ? $opex / $contributionRatio : null;

        return [
            'revenue' => round($revenue, 4),
            'cogs' => round($cogs, 4),
            'opex' => round($opex, 4),
            'ebitda' => round($ebitda, 4),
            'ebitda_margin_pct' => $revenue > 0.0 ? round($ebitda / $revenue * 100.0, 2) : 0.0,
            'net_cash_flow' => round($netCash, 4),
            'break_even_revenue' => $breakEven !== null ? round($breakEven, 4) : null,
            'break_even_headroom_pct' => $breakEven !== null && $revenue > 0.0 ? round(($revenue - $breakEven) / $revenue * 100.0, 2) : null,
        ];
    }
}
