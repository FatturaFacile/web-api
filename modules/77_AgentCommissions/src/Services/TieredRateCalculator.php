<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Modules\Payroll\Calculation\Dec;

/**
 * Progressive (marginal) commission over the cumulative turnover of a rule: the part of a new base that falls inside
 * a tier earns the rate of that tier, exactly like income-tax brackets. A base that crosses a threshold is split.
 */
final class TieredRateCalculator
{
    /**
     * @param  list<array{from: string, to: string|null, rate: string}>  $tiers  ordered by `from`
     * @return array{commission: string, effective_rate: string|null}
     */
    public static function calculate(string $base, string $cumulativeBefore, array $tiers): array
    {
        if (Dec::isZero($base) || $tiers === []) {
            return ['commission' => '0.00', 'effective_rate' => null];
        }

        $start = Dec::max($cumulativeBefore, '0');
        $end = Dec::add($start, Dec::max($base, '0'));
        $commission = '0';

        foreach ($tiers as $tier) {
            $low = Dec::max($tier['from'], $start);
            $high = $tier['to'] === null ? $end : Dec::min($tier['to'], $end);

            if (Dec::cmp($high, $low) <= 0) {
                continue;
            }

            $commission = Dec::add($commission, Dec::div(Dec::mul(Dec::sub($high, $low), $tier['rate']), '100'));
        }

        $positiveBase = Dec::max($base, '0');
        $rounded = Dec::round($commission);

        return [
            'commission' => $rounded,
            'effective_rate' => Dec::isPositive($positiveBase) ? Dec::round(Dec::div(Dec::mul($rounded, '100'), $positiveBase), 4) : null,
        ];
    }

    /**
     * A tier table must start at zero and be contiguous, so that no part of the turnover is left without a rate.
     *
     * @param  list<array{from: string, to: string|null, rate: string}>  $tiers
     */
    public static function isContiguous(array $tiers): bool
    {
        if ($tiers === [] || Dec::cmp($tiers[0]['from'], '0') !== 0) {
            return false;
        }

        $last = count($tiers) - 1;

        foreach ($tiers as $index => $tier) {
            if ($tier['to'] === null) {
                if ($index !== $last) {
                    return false;
                }

                continue;
            }

            if (Dec::cmp($tier['to'], $tier['from']) <= 0) {
                return false;
            }

            if ($index < $last && Dec::cmp($tiers[$index + 1]['from'], $tier['to']) !== 0) {
                return false;
            }
        }

        return true;
    }
}
