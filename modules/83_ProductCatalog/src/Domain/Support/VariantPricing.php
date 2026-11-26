<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Support;

use Modules\ProductCatalog\Domain\Enums\DeltaType;

/**
 * Price and cost of a variant relative to its parent: surcharges are either fixed amounts or percentages of the parent figure.
 * Several surcharges add up: the percentages are summed and applied to the parent once, the fixed amounts are added on top
 * (parent x (1 + sum of percentages / 100) + sum of fixed amounts), rounded to four decimals and never below zero.
 */
final class VariantPricing
{
    public const int DECIMALS = 4;

    /**
     * @param  list<array{amount: string|int|float|null, type: string|null}>  $deltas
     */
    public static function resolve(string|int|float|null $parentFigure, array $deltas): string
    {
        $percent = Dec::of(0);
        $fixed = Dec::of(0);

        foreach ($deltas as $delta) {
            if ($delta['amount'] === null || $delta['amount'] === '') {
                continue;
            }

            if (($delta['type'] ?? DeltaType::Fixed->value) === DeltaType::Percent->value) {
                $percent = Dec::add($percent, $delta['amount']);
            } else {
                $fixed = Dec::add($fixed, $delta['amount']);
            }
        }

        $factor = Dec::add(1, Dec::div($percent, 100));
        $figure = Dec::add(Dec::mul($parentFigure, $factor), $fixed);

        return Dec::cmp($figure, 0) < 0 ? Dec::round(0, self::DECIMALS) : Dec::round($figure, self::DECIMALS);
    }
}
