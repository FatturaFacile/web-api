<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Support;

use Modules\SubscriptionBilling\Models\SubscriptionPlan;

/**
 * Fixed recurring charge of a plan for a given number of seats/units.
 *
 * - flat_fee / metered / hybrid: base price (consumption is rated separately by the usage engine)
 * - per_unit: base price x seats
 * - tiered (graduated): base price + sum of each tier's units x tier unit price (+ tier flat fee)
 * - volume: base price + all units at the unit price of the tier reached (+ its flat fee)
 */
final class PlanPricing
{
    public static function recurringAmount(SubscriptionPlan $plan, int $seats = 1): float
    {
        $seats = max(1, $seats);
        $base = (float) $plan->base_price;

        return round(match ($plan->pricing_model) {
            'per_unit' => $base * $seats,
            'tiered' => $base + self::graduated($plan, $seats),
            'volume' => $base + self::volume($plan, $seats),
            default => $base,
        }, 2);
    }

    private static function graduated(SubscriptionPlan $plan, int $seats): float
    {
        $total = 0.0;

        foreach ($plan->pricingTiers as $tier) {
            $from = (float) $tier->from_quantity;
            $to = $tier->to_quantity === null ? (float) $seats : min((float) $tier->to_quantity, (float) $seats);
            $units = max(0.0, $to - max($from, 1.0) + 1.0);

            if ($units > 0) {
                $total += $units * (float) $tier->unit_price + (float) $tier->flat_fee;
            }
        }

        return $total;
    }

    private static function volume(SubscriptionPlan $plan, int $seats): float
    {
        foreach ($plan->pricingTiers as $tier) {
            $to = $tier->to_quantity === null ? PHP_FLOAT_MAX : (float) $tier->to_quantity;

            if ($seats >= (float) $tier->from_quantity && $seats <= $to) {
                return $seats * (float) $tier->unit_price + (float) $tier->flat_fee;
            }
        }

        return 0.0;
    }
}
