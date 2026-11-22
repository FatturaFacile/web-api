<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use Modules\Inventory\Models\InventoryCountPolicy;
use Modules\Inventory\Support\Quantity;

/**
 * The arithmetic of count variances, free of persistence.
 *
 * - variance quantity = final counted quantity - system quantity; variance value = variance quantity x unit cost;
 * - variance percentage = |variance quantity| / system quantity x 100; undefined (null) when the system quantity is
 *   zero and something was counted (anything found where nothing was expected is an unbounded variance), 0 when both
 *   are zero;
 * - a recount is due when a variance exceeds the policy's recount thresholds (percentage and/or value, either one);
 * - the approval level of a count is 0 (within tolerance), 1 (warehouse manager) or 2 (warehouse manager, then
 *   finance): level 2 when the gross absolute variance value or the worst line percentage exceeds the level 2
 *   thresholds, else level 1 when it exceeds the level 1 thresholds. An undefined percentage exceeds every percentage
 *   threshold. Without any threshold configured a count is within tolerance.
 */
final class CountVarianceMath
{
    public static function percentage(string $systemQuantity, string $countedQuantity): ?string
    {
        if (Quantity::isZero($systemQuantity)) {
            return Quantity::isZero($countedQuantity) ? '0.00' : null;
        }

        $delta = Quantity::abs(Quantity::sub($countedQuantity, $systemQuantity));

        return (string) Quantity::div(Quantity::mul($delta, '100', 10), Quantity::abs($systemQuantity), 2);
    }

    public static function recountDue(InventoryCountPolicy $policy, string $varianceValue, ?string $percentage, bool $nothingExpected, int $recountsDone): bool
    {
        if ($recountsDone >= $policy->max_recount_rounds) {
            return false;
        }

        return self::exceeds($policy->recount_threshold_percent, $policy->recount_threshold_value, $percentage, $varianceValue, $nothingExpected);
    }

    /**
     * @param  list<array{variance_value: string, variance_percentage: ?string, variance_quantity: string}>  $lines  only the lines that differ
     */
    public static function approvalLevel(InventoryCountPolicy $policy, array $lines): int
    {
        if ($lines === []) {
            return 0;
        }

        $gross = '0.00';
        $worst = '0.00';
        $undefined = false;

        foreach ($lines as $line) {
            $gross = Quantity::add($gross, Quantity::abs($line['variance_value'], 2), 2);

            if ($line['variance_percentage'] === null) {
                $undefined = true;
            } else {
                $worst = Quantity::max($worst, $line['variance_percentage'], 2);
            }
        }

        $percentage = $undefined ? null : $worst;

        return match (true) {
            self::exceeds($policy->level2_threshold_percent, $policy->level2_threshold_value, $percentage, $gross, $undefined) => 2,
            self::exceeds($policy->level1_threshold_percent, $policy->level1_threshold_value, $percentage, $gross, $undefined) => 1,
            default => 0,
        };
    }

    private static function exceeds(?string $thresholdPercent, ?string $thresholdValue, ?string $percentage, string $value, bool $undefinedPercentage): bool
    {
        if ($thresholdPercent !== null && ($undefinedPercentage || ($percentage !== null && Quantity::compare($percentage, $thresholdPercent) > 0))) {
            return true;
        }

        return $thresholdValue !== null && Quantity::compare(Quantity::abs($value, 2), $thresholdValue) > 0;
    }
}
