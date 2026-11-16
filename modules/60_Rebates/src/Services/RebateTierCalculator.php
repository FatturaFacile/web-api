<?php

declare(strict_types=1);

namespace Modules\Rebates\Services;

use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Rebates\Models\RebateAgreement;

/**
 * Pure arithmetic of the rebate scale. Retroactive: the rate of the highest tier reached applies to the whole volume.
 * Incremental: every band of volume between two thresholds earns the rate of its own tier. Volume below the first
 * threshold earns nothing.
 */
final class RebateTierCalculator
{
    /**
     * @param  list<array{threshold_amount: string, rate_percent: string}>  $tiers
     * @return array{rebate: string, rate_percent: string|null}
     */
    public function rebateOn(array $tiers, string $volume, string $mode): array
    {
        usort($tiers, static fn (array $left, array $right): int => Decimal::compare($left['threshold_amount'], $right['threshold_amount']));

        if (! Decimal::isPositive($volume)) {
            return ['rebate' => '0.00', 'rate_percent' => null];
        }

        $reached = array_values(array_filter($tiers, static fn (array $tier): bool => Decimal::compare($volume, $tier['threshold_amount']) >= 0));

        if ($reached === []) {
            return ['rebate' => '0.00', 'rate_percent' => null];
        }

        $top = $reached[array_key_last($reached)];

        if ($mode === RebateAgreement::Retroactive) {
            return ['rebate' => Decimal::round2(Decimal::div(Decimal::mul($volume, $top['rate_percent']), '100')), 'rate_percent' => $top['rate_percent']];
        }

        $total = '0';

        foreach ($reached as $index => $tier) {
            $upper = $reached[$index + 1]['threshold_amount'] ?? $volume;
            $band = Decimal::sub($upper, $tier['threshold_amount']);
            $total = Decimal::add($total, Decimal::div(Decimal::mul($band, $tier['rate_percent']), '100'));
        }

        return ['rebate' => Decimal::round2($total), 'rate_percent' => $top['rate_percent']];
    }
}
