<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Rebates\Models\RebateAgreement;

/**
 * Pure arithmetic of the rebate scale. Retroactive: the rate of the highest tier reached applies to the whole volume.
 * Incremental: every band of volume between two thresholds earns the rate of its own tier. Volume below the first
 * threshold earns nothing. A tier may also carry a flat amount: it is earned once when the tier is reached (retroactive:
 * the flat amount of the highest tier reached, incremental: the flat amounts of all the tiers reached).
 */
final class RebateTierCalculator
{
    /**
     * @param  list<array{threshold_amount: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @return array{rebate: string, rate_percent: string|null}
     */
    public function rebateOn(array $tiers, string $volume, string $mode): array
    {
        $tiers = $this->sorted($tiers);

        if (! Decimal::isPositive($volume)) {
            return ['rebate' => '0.00', 'rate_percent' => null];
        }

        $reached = array_values(array_filter($tiers, static fn (array $tier): bool => Decimal::compare($volume, $tier['threshold_amount']) >= 0));

        if ($reached === []) {
            return ['rebate' => '0.00', 'rate_percent' => null];
        }

        $top = $reached[array_key_last($reached)];

        if ($mode === RebateAgreement::Retroactive) {
            $percent = Decimal::div(Decimal::mul($volume, $top['rate_percent']), '100');

            return ['rebate' => Decimal::round2(Decimal::add($percent, $top['flat_amount'] ?? '0')), 'rate_percent' => $top['rate_percent']];
        }

        $total = '0';

        foreach ($reached as $index => $tier) {
            $upper = $reached[$index + 1]['threshold_amount'] ?? $volume;
            $band = Decimal::sub($upper, $tier['threshold_amount']);
            $total = Decimal::add($total, Decimal::add(Decimal::div(Decimal::mul($band, $tier['rate_percent']), '100'), $tier['flat_amount'] ?? '0'));
        }

        return ['rebate' => Decimal::round2($total), 'rate_percent' => $top['rate_percent']];
    }

    /**
     * Fixed target bonus: the whole bonus is earned once the qualifying spend reaches the target.
     */
    public function fixedBonusOn(string $target, string $bonus, string $volume): string
    {
        return Decimal::compare($volume, $target) >= 0 && Decimal::isPositive($volume) ? Decimal::round2($bonus) : '0.00';
    }

    /**
     * Where the volume stands on the scale: the tier it is in, the next tier, the distance to it and the rebate that
     * the volume would earn when it gets there.
     *
     * @param  list<array{threshold_amount: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @return array{current_tier: array{threshold_amount: string, rate_percent: string, flat_amount: string|null}|null, next_tier: array{threshold_amount: string, rate_percent: string, flat_amount: string|null}|null, accrued_rebate: string, distance_to_next: string|null, progress_percent: string, projected_rebate_at_next: string|null, projected_gain: string|null}
     */
    public function progress(array $tiers, string $volume, string $mode): array
    {
        $tiers = $this->sorted($tiers);
        $current = null;
        $next = null;

        foreach ($tiers as $tier) {
            if (Decimal::compare($volume, $tier['threshold_amount']) >= 0 && Decimal::isPositive($volume)) {
                $current = $tier;
            } elseif ($next === null) {
                $next = $tier;
            }
        }

        $accrued = $this->rebateOn($tiers, $volume, $mode)['rebate'];
        $distance = $next === null ? null : Decimal::round2(Decimal::sub($next['threshold_amount'], $volume));
        $projected = $next === null ? null : $this->rebateOn($tiers, $next['threshold_amount'], $mode)['rebate'];
        $floor = $current['threshold_amount'] ?? '0';
        $span = $next === null ? '0' : Decimal::sub($next['threshold_amount'], $floor);
        $progress = $next === null ? '100.00' : (Decimal::isPositive($span) ? Decimal::round2(Decimal::div(Decimal::mul(Decimal::sub($volume, $floor), '100'), $span)) : '0.00');

        return [
            'current_tier' => $current === null ? null : $this->shape($current),
            'next_tier' => $next === null ? null : $this->shape($next),
            'accrued_rebate' => $accrued,
            'distance_to_next' => $distance,
            'progress_percent' => Decimal::isNegative($progress) ? '0.00' : $progress,
            'projected_rebate_at_next' => $projected,
            'projected_gain' => $projected === null ? null : Decimal::round2(Decimal::sub($projected, $accrued)),
        ];
    }

    /**
     * @param  list<array{threshold_amount: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @return list<array{threshold_amount: string, rate_percent: string, flat_amount?: string|null}>
     */
    private function sorted(array $tiers): array
    {
        usort($tiers, static fn (array $left, array $right): int => Decimal::compare($left['threshold_amount'], $right['threshold_amount']));

        return $tiers;
    }

    /**
     * @param  array{threshold_amount: string, rate_percent: string, flat_amount?: string|null}  $tier
     * @return array{threshold_amount: string, rate_percent: string, flat_amount: string|null}
     */
    private function shape(array $tier): array
    {
        return ['threshold_amount' => $tier['threshold_amount'], 'rate_percent' => $tier['rate_percent'], 'flat_amount' => $tier['flat_amount'] ?? null];
    }
}
