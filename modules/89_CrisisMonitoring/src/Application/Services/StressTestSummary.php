<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The compact picture of a snapshot shown side by side in the stress test (baseline against stressed), and the differences between two.
 */
final class StressTestSummary
{
    /**
     * @param  array<string, mixed>  $snapshot  see CrisisEngine::evaluate()
     * @return array<string, mixed>
     */
    public static function lite(array $snapshot): array
    {
        $liquidity = $snapshot['swiss']['art725'];
        $capital = $snapshot['swiss']['art725a'];
        $min = (string) $liquidity['min_cumulative_cash'];

        return [
            'dscr' => $snapshot['dscr'],
            'liquidity' => [
                'min_cumulative_cash' => $min,
                'first_negative_month' => $liquidity['first_negative_month'],
                'liquidity_gap' => Decimal::isNegative($min) ? Decimal::round2(Decimal::abs($min)) : '0.00',
            ],
            'capital' => ['net_assets' => $capital['net_assets'], 'coverage_ratio' => $capital['coverage_ratio'], 'status' => $capital['status']],
            'ccii_overall' => $snapshot['ccii']['overall_status'],
            'swiss_overall' => $snapshot['swiss']['overall_status'],
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline  a lite snapshot
     * @param  array<string, mixed>  $stressed  a lite snapshot
     * @return array<string, string|null>
     */
    public static function deltas(array $baseline, array $stressed): array
    {
        $diff = static fn (?string $before, ?string $after): ?string => $before === null || $after === null ? null : Decimal::round2(Decimal::sub($after, $before));

        return [
            'dscr_treasury_m6' => $diff($baseline['dscr']['treasury']['m6']['value'], $stressed['dscr']['treasury']['m6']['value']),
            'dscr_treasury_m12' => $diff($baseline['dscr']['treasury']['m12']['value'], $stressed['dscr']['treasury']['m12']['value']),
            'dscr_operating_m6' => $diff($baseline['dscr']['operating']['m6']['value'], $stressed['dscr']['operating']['m6']['value']),
            'dscr_operating_m12' => $diff($baseline['dscr']['operating']['m12']['value'], $stressed['dscr']['operating']['m12']['value']),
            'min_cumulative_cash' => $diff($baseline['liquidity']['min_cumulative_cash'], $stressed['liquidity']['min_cumulative_cash']),
            'coverage_ratio' => $diff($baseline['capital']['coverage_ratio'], $stressed['capital']['coverage_ratio']),
        ];
    }
}
