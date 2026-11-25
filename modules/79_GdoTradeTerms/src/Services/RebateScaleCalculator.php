<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Services;

use Illuminate\Support\Carbon;
use Modules\GdoTradeTerms\Domain\Enums\TierMode;
use Modules\GdoTradeTerms\Domain\Enums\VolumeMode;
use Modules\GdoTradeTerms\Support\Money;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Pure arithmetic of a tiered year-end rebate (PFA / Jahresrückvergütung). Retroactive: reaching a threshold applies the rate of
 * that tier to the whole qualifying volume from zero. Progressive (marginal): every slice between two thresholds earns the rate
 * of its own tier. A tier may carry a flat amount, earned once when the tier is reached (retroactive: the flat amount of the
 * highest tier reached; progressive: the flat amounts of all the tiers reached). The qualifying volume is the total volume or
 * only the growth over a baseline (the prior period, or an agreed figure).
 *
 * @phpstan-type Tier array{threshold: string, rate_percent: string, flat_amount?: string|null}
 */
final class RebateScaleCalculator
{
    /**
     * The volume the scale counts: the whole volume, or the growth over the baseline. Never negative.
     */
    public function qualifying(string $volume, string $baseline, VolumeMode $mode): string
    {
        $base = $mode === VolumeMode::Incremental ? Money::sub($volume, $baseline) : Money::round($volume);

        return Money::isPositive($base) ? $base : Money::round('0');
    }

    /**
     * @param  list<array{threshold: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @return array{rebate: string, rate_percent: string|null, tier: int|null}
     */
    public function earn(array $tiers, string $qualifying, TierMode $mode): array
    {
        $tiers = $this->sorted($tiers);
        $none = ['rebate' => Money::round('0'), 'rate_percent' => null, 'tier' => null];

        if (! Money::isPositive($qualifying)) {
            return $none;
        }

        $reached = [];

        foreach ($tiers as $index => $tier) {
            if (Money::compare($qualifying, $tier['threshold']) >= 0) {
                $reached[] = $index;
            }
        }

        if ($reached === []) {
            return $none;
        }

        $topIndex = $reached[array_key_last($reached)];
        $top = $tiers[$topIndex];

        if ($mode === TierMode::Retroactive) {
            $rebate = Money::add(Money::percentOf($qualifying, $top['rate_percent']), $top['flat_amount'] ?? '0');

            return ['rebate' => $rebate, 'rate_percent' => Money::round($top['rate_percent']), 'tier' => $topIndex + 1];
        }

        $total = '0';

        foreach ($reached as $index) {
            $upper = isset($tiers[$index + 1]) ? Money::min($qualifying, $tiers[$index + 1]['threshold']) : Money::round($qualifying);
            $band = Money::sub($upper, $tiers[$index]['threshold']);
            $total = Money::add($total, Money::add(Money::percentOf($band, $tiers[$index]['rate_percent']), $tiers[$index]['flat_amount'] ?? '0'));
        }

        return ['rebate' => $total, 'rate_percent' => Money::round($top['rate_percent']), 'tier' => $topIndex + 1];
    }

    /**
     * Year-end target attainment: the run rate of the elapsed part of the period projected to its end, the rebate it would
     * earn, the tier it lands in and what the next tier asks for.
     *
     * @param  list<array{threshold: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @return array<string, mixed>
     */
    public function projection(array $tiers, TierMode $mode, VolumeMode $volumeMode, string $volumeToDate, string $baseline, Carbon $start, Carbon $end, Carbon $asOf): array
    {
        $tiers = $this->sorted($tiers);
        $totalDays = max(1, (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);
        $elapsed = match (true) {
            $asOf->lt($start) => 0,
            $asOf->gte($end) => $totalDays,
            default => (int) $start->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay()) + 1,
        };
        $remaining = $totalDays - $elapsed;
        $projectedVolume = match (true) {
            $elapsed === 0 => Money::round('0'),
            $remaining === 0 => Money::round($volumeToDate),
            default => Money::round(Decimal::div(Decimal::mul(Money::isPositive($volumeToDate) ? $volumeToDate : '0', (string) $totalDays), (string) $elapsed)),
        };

        $qualifyingToDate = $this->qualifying($volumeToDate, $baseline, $volumeMode);
        $projectedQualifying = $this->qualifying($projectedVolume, $baseline, $volumeMode);
        $earnedToDate = $this->earn($tiers, $qualifyingToDate, $mode);
        $projected = $this->earn($tiers, $projectedQualifying, $mode);

        $next = null;

        foreach ($tiers as $index => $tier) {
            if (Money::compare($projectedQualifying, $tier['threshold']) < 0) {
                $next = ['position' => $index + 1] + $this->shape($tier);
                break;
            }
        }

        $gap = $next === null ? null : Money::sub($next['threshold'], $projectedQualifying);
        $required = null;

        if ($next !== null && $remaining > 0) {
            $needed = $volumeMode === VolumeMode::Incremental ? Money::add($next['threshold'], $baseline) : $next['threshold'];
            $missing = Money::sub($needed, $volumeToDate);
            $required = Money::isPositive($missing) ? Money::round(Decimal::div($missing, (string) $remaining)) : Money::round('0');
        }

        return [
            'elapsed_days' => $elapsed,
            'total_days' => $totalDays,
            'remaining_days' => $remaining,
            'time_progress_percent' => Money::round(Decimal::div(Decimal::mul((string) $elapsed, '100'), (string) $totalDays), 2),
            'volume_to_date' => Money::round($volumeToDate),
            'projected_volume' => $projectedVolume,
            'qualifying_to_date' => $qualifyingToDate,
            'projected_qualifying' => $projectedQualifying,
            'earned_to_date' => $earnedToDate['rebate'],
            'tier_to_date' => $earnedToDate['tier'],
            'projected_rebate' => $projected['rebate'],
            'projected_tier' => $projected['tier'],
            'projected_rate_percent' => $projected['rate_percent'],
            'projected_effective_percent' => Money::isPositive($projectedVolume) ? Money::round(Decimal::div(Decimal::mul($projected['rebate'], '100'), $projectedVolume)) : Money::round('0'),
            'next_tier' => $next,
            'gap_to_next' => $gap,
            'required_daily_volume' => $required,
            'attainment_percent' => $next === null ? Money::round('100', 2) : Money::round(Decimal::div(Decimal::mul($projectedQualifying, '100'), $next['threshold']), 2),
        ];
    }

    /**
     * @param  list<array{threshold: string, rate_percent: string, flat_amount?: string|null}>  $tiers
     * @return list<array{threshold: string, rate_percent: string, flat_amount?: string|null}>
     */
    public function sorted(array $tiers): array
    {
        usort($tiers, static fn (array $left, array $right): int => Money::compare($left['threshold'], $right['threshold']));

        return $tiers;
    }

    /**
     * @param  array{threshold: string, rate_percent: string, flat_amount?: string|null}  $tier
     * @return array{threshold: string, rate_percent: string, flat_amount: string|null}
     */
    private function shape(array $tier): array
    {
        return ['threshold' => Money::round($tier['threshold']), 'rate_percent' => Money::round($tier['rate_percent']), 'flat_amount' => isset($tier['flat_amount']) ? Money::round($tier['flat_amount']) : null];
    }
}
