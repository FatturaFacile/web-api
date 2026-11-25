<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;

/**
 * Progressive percentage brackets (aliquote a scaglioni): each rate applies only to the slice of sales that falls inside its
 * bracket, like income tax. A bracket is `{from, to, rate}` with `to` null for the open top bracket and `rate` in percent.
 */
final class TieredRateCalculator
{
    /**
     * Checks that the brackets start at zero, are contiguous and ascending, end with an open bracket and carry rates in 0..100.
     *
     * @param  list<array{from: string|int|float, to: string|int|float|null, rate: string|int|float}>  $tiers
     *
     * @throws FranchiseNetworkException
     */
    public static function assertValid(array $tiers): void
    {
        if ($tiers === []) {
            throw new FranchiseNetworkException('tiers_required');
        }

        $expectedFrom = '0';

        foreach ($tiers as $index => $tier) {
            if (Dec::cmp($tier['from'], $expectedFrom) !== 0) {
                throw new FranchiseNetworkException('tiers_not_contiguous');
            }

            if (Dec::cmp($tier['rate'], 0) < 0 || Dec::cmp($tier['rate'], 100) > 0) {
                throw new FranchiseNetworkException('tier_rate_out_of_range');
            }

            $isLast = $index === count($tiers) - 1;

            if ($isLast) {
                if ($tier['to'] !== null) {
                    throw new FranchiseNetworkException('tiers_top_must_be_open');
                }

                return;
            }

            if ($tier['to'] === null || Dec::cmp($tier['to'], $tier['from']) <= 0) {
                throw new FranchiseNetworkException('tiers_not_ascending');
            }

            $expectedFrom = Dec::of($tier['to']);
        }
    }

    /**
     * @param  list<array{from: string|int|float, to: string|int|float|null, rate: string|int|float}>  $tiers
     * @return array{amount: string, slices: list<array{from: string, to: string|null, rate: string, base: string, amount: string}>}
     */
    public static function calculate(string|int|float $sales, array $tiers): array
    {
        $slices = [];
        $total = '0';

        foreach ($tiers as $tier) {
            if (Dec::cmp($sales, $tier['from']) <= 0) {
                break;
            }

            $upper = $tier['to'] === null ? Dec::of($sales) : Dec::min($sales, $tier['to']);
            $base = Dec::sub($upper, $tier['from']);
            $amount = Dec::div(Dec::mul($base, $tier['rate']), 100);
            $total = Dec::add($total, $amount);
            $slices[] = [
                'from' => Dec::round($tier['from']),
                'to' => $tier['to'] === null ? null : Dec::round($tier['to']),
                'rate' => (string) $tier['rate'],
                'base' => Dec::round($base),
                'amount' => Dec::round($amount),
            ];
        }

        return ['amount' => Dec::round($total), 'slices' => $slices];
    }
}
