<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

/**
 * Inter-store compensation of the shared loyalty programme. A customer who earns points in store A and redeems them in store B
 * makes B give away value that A collected: B owes A the value of the redeemed points. The franchisor clears the net position of
 * every store in the monthly invoice: positive = the store owes the network, negative = the network owes the store.
 */
final class LoyaltySettlementCalculator
{
    /**
     * @param  list<array{store_id: string, earned_store_id: string|null, value_amount: string|int|float}>  $redemptions  redeem rows of the month
     * @return array<string, array{owed: string, receivable: string, net: string}> by store id
     */
    public static function settle(array $redemptions): array
    {
        $positions = [];

        foreach ($redemptions as $row) {
            $earned = $row['earned_store_id'] ?? null;

            if ($earned === null || $earned === $row['store_id']) {
                continue;
            }

            $positions[$row['store_id']] ??= ['owed' => '0', 'receivable' => '0'];
            $positions[$earned] ??= ['owed' => '0', 'receivable' => '0'];
            $positions[$row['store_id']]['owed'] = Dec::add($positions[$row['store_id']]['owed'], $row['value_amount']);
            $positions[$earned]['receivable'] = Dec::add($positions[$earned]['receivable'], $row['value_amount']);
        }

        return array_map(static fn (array $position): array => [
            'owed' => Dec::round($position['owed']),
            'receivable' => Dec::round($position['receivable']),
            'net' => Dec::round(Dec::sub($position['owed'], $position['receivable'])),
        ], $positions);
    }
}
