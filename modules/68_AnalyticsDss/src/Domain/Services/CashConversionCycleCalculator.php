<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Domain\Services;

/**
 * Working capital metrics: CCC = DIO + DSO - DPO, working capital ratio, free cash flow yield, burn rate and runway.
 */
final class CashConversionCycleCalculator
{
    /**
     * @param  float  $receivables  Average or closing trade receivables.
     * @param  float  $payables  Average or closing trade payables.
     * @param  float  $inventory  Average or closing inventory value.
     * @param  float  $revenue  Revenue of the period.
     * @param  float  $cogs  Cost of goods sold of the period.
     * @param  float  $currentAssets  Current assets (for the working capital ratio).
     * @param  float  $currentLiabilities  Current liabilities (for the working capital ratio).
     * @param  float  $operatingCashFlow  Operating cash flow of the period.
     * @param  float  $capex  Capital expenditure of the period (positive number).
     * @param  float  $cashBalance  Cash and equivalents at the end of the period.
     * @param  float  $netCashFlow  Net cash movement of the period (negative: burning cash).
     * @param  int  $days  Length of the period in days.
     * @return array<string, float|null>
     */
    public function calculate(
        float $receivables,
        float $payables,
        float $inventory,
        float $revenue,
        float $cogs,
        float $currentAssets,
        float $currentLiabilities,
        float $operatingCashFlow,
        float $capex,
        float $cashBalance,
        float $netCashFlow,
        int $days,
    ): array {
        $days = max($days, 1);
        $dso = $revenue > 0.0 ? $receivables / $revenue * $days : 0.0;
        $dio = $cogs > 0.0 ? $inventory / $cogs * $days : 0.0;
        $dpo = $cogs > 0.0 ? $payables / $cogs * $days : 0.0;
        $freeCashFlow = $operatingCashFlow - $capex;
        $monthlyBurn = $netCashFlow < 0.0 ? abs($netCashFlow) / $days * 30.0 : 0.0;

        return [
            'dso_days' => round($dso, 2),
            'dio_days' => round($dio, 2),
            'dpo_days' => round($dpo, 2),
            'ccc_days' => round($dio + $dso - $dpo, 2),
            'working_capital' => round($currentAssets - $currentLiabilities, 4),
            'working_capital_ratio' => $currentLiabilities > 0.0 ? round($currentAssets / $currentLiabilities, 4) : null,
            'free_cash_flow' => round($freeCashFlow, 4),
            'free_cash_flow_yield_pct' => $revenue > 0.0 ? round($freeCashFlow / $revenue * 100.0, 2) : null,
            'burn_rate_monthly' => round($monthlyBurn, 4),
            'runway_months' => $monthlyBurn > 0.0 ? round($cashBalance / $monthlyBurn, 2) : null,
        ];
    }
}
