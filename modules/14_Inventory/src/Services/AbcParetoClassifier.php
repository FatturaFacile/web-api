<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Support\Quantity;

/**
 * The Pareto arithmetic of the ABC classification, free of persistence: entries are ranked by value (descending, ties
 * broken by id so the order is stable), the cumulative share of the total value is computed and the class assigned.
 *
 * - An entry with no value is class C (it adds nothing to the cumulative share).
 * - Entries with the same value form a group that gets one class: the one of the cumulative share at the end of the
 *   group, so that equal items are never split between two classes by their ids.
 * - A group that would not be A because it crosses the A share, while no entry is A yet, is A (a single dominant item).
 * - With a zero total every entry is C.
 */
final class AbcParetoClassifier
{
    /**
     * @param  list<array{id: string, value: string}>  $entries
     * @return list<array{id: string, value: string, rank: int, cumulative_share: string, abc_class: string}>
     */
    public static function classify(array $entries, string $aShare = '80', string $bShare = '95'): array
    {
        usort($entries, static fn (array $left, array $right): int => Quantity::compare($right['value'], $left['value']) ?: strcmp($left['id'], $right['id']));

        $total = '0.00';

        foreach ($entries as $entry) {
            $total = Quantity::add($total, $entry['value'], 2);
        }

        $rows = [];
        $running = '0.00';
        $hasA = false;
        $count = count($entries);
        $index = 0;

        while ($index < $count) {
            $end = $index;

            while ($end + 1 < $count && Quantity::compare($entries[$end + 1]['value'], $entries[$index]['value']) === 0) {
                $end++;
            }

            $groupValue = $entries[$index]['value'];
            $groupSize = $end - $index + 1;
            $running = Quantity::add($running, Quantity::mul($groupValue, (string) $groupSize, 2), 2);
            $share = Quantity::isPositive($total) ? (string) Quantity::div(Quantity::mul($running, '100', 10), $total, 2) : '100.00';

            $class = match (true) {
                ! Quantity::isPositive($groupValue) => 'C',
                Quantity::compare($share, $aShare) <= 0 => 'A',
                Quantity::compare($share, $bShare) <= 0 => 'B',
                default => 'C',
            };

            if ($class !== 'A' && Quantity::isPositive($groupValue) && ! $hasA) {
                $class = 'A';
            }

            $hasA = $hasA || $class === 'A';

            for ($position = $index; $position <= $end; $position++) {
                $rows[] = [
                    'id' => $entries[$position]['id'],
                    'value' => $entries[$position]['value'],
                    'rank' => $position + 1,
                    'cumulative_share' => $share,
                    'abc_class' => $class,
                ];
            }

            $index = $end + 1;
        }

        return $rows;
    }
}
