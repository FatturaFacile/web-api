<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

/**
 * The PMI earned value formulas on exact decimal strings, free of any I/O so every edge case can be tested directly.
 *
 *  EV  = progress x BAC (progress is a ratio, 0..1)
 *  CV  = EV - AC (positive: under budget), SV = EV - PV (positive: ahead of schedule)
 *  CPI = EV / AC and SPI = EV / PV; both are null (undefined) when the divisor is zero, never NaN or infinite
 *  EAC = BAC / CPI while CPI > 0; with a manual bottom-up ETC, EAC = AC + ETC
 *  ETC = max(EAC - AC, 0) for the formula, the manual value as entered otherwise
 *  VAC = BAC - EAC
 *  absorption = AC / BAC x 100, null while BAC is zero
 *
 * Without a usable CPI (no cost yet, or cost without progress) the forecast cannot be extrapolated: the EAC falls back
 * to the larger of BAC and AC, so a project that already spent more than its budget never forecasts below the spend.
 */
final class EvmCalculator
{
    public const string CalculationVersion = '1.0';

    /**
     * @param  string|int|float|null  $progress  ratio 0..1 (clamped)
     * @return array{budget_at_completion: string, planned_value: string, earned_value: string, actual_cost: string, cost_variance: string, schedule_variance: string, cost_performance_index: string|null, schedule_performance_index: string|null, estimate_at_completion: string, estimate_to_complete: string, variance_at_completion: string, budget_absorption_percent: string|null, manual_etc_override: string|null}
     */
    public static function compute(string|int|float|null $bac, string|int|float|null $progress, string|int|float|null $plannedProgress, string|int|float|null $actualCost, string|int|float|null $manualEtc = null): array
    {
        $bac = Money::max($bac, 0);
        $ac = Money::max($actualCost, 0);
        $ev = Money::mul($bac, self::ratio($progress));
        $pv = Money::mul($bac, self::ratio($plannedProgress));

        $cpi = Money::div($ev, $ac);
        $spi = Money::div($ev, $pv);

        if ($manualEtc !== null && $manualEtc !== '') {
            $etc = Money::max($manualEtc, 0);
            $eac = Money::add($ac, $etc);
        } else {
            $eac = $cpi !== null && Money::isPositive($cpi) ? (Money::div($bac, $cpi) ?? $bac) : Money::max($bac, $ac);
            $etc = Money::max(Money::sub($eac, $ac), 0);
        }

        return [
            'budget_at_completion' => Money::round($bac, 4),
            'planned_value' => Money::round($pv, 4),
            'earned_value' => Money::round($ev, 4),
            'actual_cost' => Money::round($ac, 4),
            'cost_variance' => Money::round(Money::sub($ev, $ac), 4),
            'schedule_variance' => Money::round(Money::sub($ev, $pv), 4),
            'cost_performance_index' => $cpi === null ? null : Money::round($cpi, 4),
            'schedule_performance_index' => $spi === null ? null : Money::round($spi, 4),
            'estimate_at_completion' => Money::round($eac, 4),
            'estimate_to_complete' => Money::round($etc, 4),
            'variance_at_completion' => Money::round(Money::sub($bac, $eac), 4),
            'budget_absorption_percent' => Money::isPositive($bac) ? Money::percent(Money::div($ac, $bac)) : null,
            'manual_etc_override' => $manualEtc !== null && $manualEtc !== '' ? Money::round($manualEtc, 4) : null,
        ];
    }

    /**
     * Gross margin of a node: recognised revenue minus actual cost; the percentage is null without revenue.
     *
     * @return array{gross_margin_amount: string, gross_margin_percent: string|null}
     */
    public static function margin(string|int|float|null $recognisedRevenue, string|int|float|null $actualCost): array
    {
        $margin = Money::sub($recognisedRevenue, $actualCost);

        return [
            'gross_margin_amount' => Money::round($margin, 4),
            'gross_margin_percent' => Money::isPositive($recognisedRevenue) ? Money::percent(Money::div($margin, $recognisedRevenue)) : null,
        ];
    }

    /**
     * The thresholds that a node breaches: CPI or SPI below 0.90 and budget absorption above 80%.
     *
     * @return list<array{metric: string, value: string, threshold: string, severity: string}>
     */
    public static function breaches(?string $cpi, ?string $spi, ?string $absorptionPercent): array
    {
        $breaches = [];

        foreach (['cpi' => $cpi, 'spi' => $spi] as $metric => $value) {
            if ($value !== null && Money::compare($value, '0.90') < 0) {
                $breaches[] = ['metric' => $metric, 'value' => $value, 'threshold' => '0.9000', 'severity' => Money::compare($value, '0.75') < 0 ? 'critical' : 'warning'];
            }
        }

        if ($absorptionPercent !== null && Money::compare($absorptionPercent, '80') > 0) {
            $breaches[] = ['metric' => 'absorption', 'value' => $absorptionPercent, 'threshold' => '80.0000', 'severity' => Money::compare($absorptionPercent, '100') >= 0 ? 'critical' : 'warning'];
        }

        return $breaches;
    }

    /**
     * Traffic light of an index: green from 1.00, amber from 0.90, red below; null (undefined) counts as green.
     */
    public static function trafficLight(?string $index): string
    {
        if ($index === null || Money::compare($index, '1') >= 0) {
            return 'green';
        }

        return Money::compare($index, '0.90') >= 0 ? 'amber' : 'red';
    }

    private static function ratio(string|int|float|null $value): string
    {
        return Money::min(Money::max($value, 0), 1);
    }
}
