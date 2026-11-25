<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

use Carbon\CarbonImmutable;

/**
 * Deferral (risconto) of the initial entry fee: the fee is recognised as revenue in equal monthly shares over the deferral months
 * starting from the month of the agreement start; the last share absorbs the rounding so the schedule always adds up to the fee.
 */
final class EntryFeeSchedule
{
    /**
     * @return list<array{period: string, amount: string}>
     */
    public static function build(string|int|float $fee, int $months, CarbonImmutable $start): array
    {
        if (Dec::cmp($fee, 0) <= 0) {
            return [];
        }

        $months = max(1, $months);
        $share = Dec::round(Dec::div($fee, $months));
        $rows = [];
        $assigned = '0';

        for ($i = 0; $i < $months; $i++) {
            $amount = $i === $months - 1 ? Dec::sub($fee, $assigned) : $share;
            $assigned = Dec::add($assigned, $amount);
            $rows[] = ['period' => $start->startOfMonth()->addMonthsNoOverflow($i)->format('Y-m'), 'amount' => Dec::round($amount)];
        }

        return $rows;
    }
}
