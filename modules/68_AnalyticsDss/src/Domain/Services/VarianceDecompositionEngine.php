<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Services;

use Modules\AnalyticsDss\Domain\ValueObjects\VarianceSide;

/**
 * Decomposes the difference between a baseline (budget, forecast or prior period) and the actual into volume, price, cost,
 * opex and FX translation effects. The bridge always closes: baseline + sum of effects = actual (reported).
 */
final class VarianceDecompositionEngine
{
    /**
     * @param  float  $fxEbitdaImpact  EBITDA translation effect: reported actual minus the actual at baseline rates.
     * @param  float  $fxRevenueImpact  Revenue translation effect.
     * @return array{ebitda_bridge: list<array<string, mixed>>, revenue_bridge: list<array<string, mixed>>, summary: array<string, float>}
     */
    public function decompose(VarianceSide $baseline, VarianceSide $actual, float $fxEbitdaImpact = 0.0, float $fxRevenueImpact = 0.0): array
    {
        $volume = ($actual->units - $baseline->units) * ($baseline->unitPrice - $baseline->unitCost);
        $price = ($actual->unitPrice - $baseline->unitPrice) * $actual->units;
        $cost = -($actual->unitCost - $baseline->unitCost) * $actual->units;
        $opex = -($actual->opex - $baseline->opex);

        $baseEbitda = $baseline->ebitda();
        $actualEbitda = $baseEbitda + $volume + $price + $cost + $opex + $fxEbitdaImpact;

        $revenueVolume = ($actual->units - $baseline->units) * $baseline->unitPrice;
        $revenuePrice = ($actual->unitPrice - $baseline->unitPrice) * $actual->units;
        $baseRevenue = $baseline->revenue();
        $actualRevenue = $baseRevenue + $revenueVolume + $revenuePrice + $fxRevenueImpact;

        return [
            'ebitda_bridge' => $this->bridge($baseEbitda, [
                'volume' => $volume,
                'price' => $price,
                'cogs' => $cost,
                'opex' => $opex,
                'fx' => $fxEbitdaImpact,
            ], $actualEbitda),
            'revenue_bridge' => $this->bridge($baseRevenue, [
                'volume' => $revenueVolume,
                'price' => $revenuePrice,
                'fx' => $fxRevenueImpact,
            ], $actualRevenue),
            'summary' => [
                'baseline_ebitda' => round($baseEbitda, 4),
                'actual_ebitda' => round($actualEbitda, 4),
                'delta' => round($actualEbitda - $baseEbitda, 4),
                'delta_pct' => $baseEbitda != 0.0 ? round(($actualEbitda - $baseEbitda) / abs($baseEbitda) * 100.0, 2) : 0.0,
                'baseline_revenue' => round($baseRevenue, 4),
                'actual_revenue' => round($actualRevenue, 4),
            ],
        ];
    }

    /**
     * Budget/Actual/Forecast delta and delta percentage of one figure.
     *
     * @return array{delta: float, delta_pct: float|null}
     */
    public function delta(float $baseline, float $actual): array
    {
        return [
            'delta' => round($actual - $baseline, 4),
            'delta_pct' => $baseline != 0.0 ? round(($actual - $baseline) / abs($baseline) * 100.0, 2) : null,
        ];
    }

    /**
     * @param  array<string, float>  $effects
     * @return list<array<string, mixed>>
     */
    private function bridge(float $start, array $effects, float $end): array
    {
        $steps = [['key' => 'baseline', 'type' => 'total', 'value' => round($start, 4), 'cumulative' => round($start, 4)]];
        $running = $start;

        foreach ($effects as $key => $effect) {
            $running += $effect;
            $steps[] = ['key' => $key, 'type' => 'delta', 'value' => round($effect, 4), 'cumulative' => round($running, 4)];
        }

        $steps[] = ['key' => 'actual', 'type' => 'total', 'value' => round($end, 4), 'cumulative' => round($end, 4)];

        return $steps;
    }
}
