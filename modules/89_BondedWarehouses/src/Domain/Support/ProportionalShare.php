<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Support;

/**
 * Proportional apportionment of the amounts of a lot (value, duty, VAT, guarantee, weight) to the quantity discharged.
 *
 * The share is the difference of two cumulative figures, so the shares of the successive discharges of a lot always add up
 * to exactly the initial amount: the last discharge takes the cent that rounding left over.
 */
final class ProportionalShare
{
    /**
     * @param  int  $scale  decimals of the amount (2 for money, 3 for weights)
     */
    public static function of(float $initialAmount, float $initialQuantity, float $remainingBefore, float $quantity, int $scale = 2): float
    {
        if ($initialQuantity <= 0.0 || $quantity <= 0.0) {
            return 0.0;
        }

        $remainingAfter = max(0.0, $remainingBefore - $quantity);
        $before = round($initialAmount * ($initialQuantity - $remainingBefore) / $initialQuantity, $scale);
        $after = $remainingAfter <= 0.00005
            ? round($initialAmount, $scale)
            : round($initialAmount * ($initialQuantity - $remainingAfter) / $initialQuantity, $scale);

        return round(max(0.0, $after - $before), $scale);
    }
}
