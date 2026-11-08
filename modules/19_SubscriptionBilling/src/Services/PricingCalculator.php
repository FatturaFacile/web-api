<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\SubscriptionBilling\Models\PlanFeature;
use Modules\SubscriptionBilling\Models\PricingTier;
use Modules\SubscriptionBilling\Models\SubscriptionItem;
use Modules\SubscriptionBilling\Models\SubscriptionPlan;
use Modules\SubscriptionBilling\Support\BillingCalendar;
use Modules\SubscriptionBilling\Support\Money;

/**
 * Prices of the plans for one billing period, in decimal strings.
 *
 * - flat_fee: the base price, whatever the quantity;
 * - per_unit (per seat): base price x quantity;
 * - tiered (graduated): each unit at the price of the tier it falls in, plus the flat fee of every tier reached;
 * - volume: every unit at the price of the tier the whole quantity falls in, plus that tier's flat fee;
 * - metered / hybrid: the base price per period, the consumption is rated by rateUsage() and billed in arrears.
 */
final class PricingCalculator
{
    /** @var list<string> */
    public const array MODELS = ['flat_fee', 'per_unit', 'per_seat', 'tiered', 'volume', 'metered', 'hybrid'];

    /**
     * Recurring amount of one full period of a plan for a quantity (seats), before any discount.
     */
    public function recurringAmount(SubscriptionPlan $plan, string $quantity): string
    {
        $base = Money::of($this->raw($plan, 'base_price') ?? $plan->base_price);
        $quantity = Money::max('0', $quantity);

        return match ($plan->pricing_model) {
            'per_unit', 'per_seat' => Money::round2(Money::mul($base, $quantity)),
            'tiered' => Money::round2($this->graduated($this->tiers($plan), $quantity)),
            'volume' => Money::round2($this->volume($this->tiers($plan), $quantity)),
            default => Money::round2($base),
        };
    }

    /**
     * Recurring amount of an item for one full period: the plan price for plan items, quantity x unit price for the
     * other charges, less the item discount, times the price factor of the renewals.
     */
    public function itemAmount(SubscriptionItem $item, ?SubscriptionPlan $plan): string
    {
        if ($item->item_type === 'usage_meter') {
            return '0.00';
        }

        $quantity = Money::of($this->raw($item, 'quantity') ?? $item->quantity);
        $gross = $item->item_type === 'plan' && $plan !== null
            ? $this->recurringAmount($plan, $quantity)
            : Money::mul($quantity, Money::of($this->raw($item, 'unit_price') ?? $item->unit_price));

        $discount = Money::of($this->raw($item, 'discount_percentage') ?? $item->discount_percentage);
        $net = Money::mul($gross, Money::sub('1', Money::div($discount, '100')));
        $factor = Money::of($this->raw($item, 'price_factor') ?? $item->price_factor ?? '1');

        return Money::round2(Money::mul($net, Money::isZero($factor) ? '1' : $factor));
    }

    /**
     * Monthly recurring revenue of a full-period amount billed every `$interval`.
     */
    public function monthly(string $periodAmount, string $interval, int $count): string
    {
        return Money::round2(Money::div($periodAmount, (string) BillingCalendar::mrrDivisor($interval, $count)));
    }

    /**
     * Rates the consumption of a metered metric in one period.
     *
     * The included allowance of the plan feature is free. A metered (or hybrid) plan with tiers rates the rest through
     * its graduated tiers; otherwise the rest is charged at the overage unit price of the feature, or at the unit price
     * of the item when the feature has none.
     *
     * @return array{included: string, billable: string, unit_price: string, amount: string}
     */
    public function rateUsage(SubscriptionPlan $plan, ?PlanFeature $feature, string $consumed, string $fallbackUnitPrice): array
    {
        $included = Money::of($this->raw($feature, 'included_quantity') ?? $feature?->included_quantity);
        $billable = Money::max('0', Money::sub($consumed, $included));
        $tiers = $this->tiers($plan);
        $featureHasPrice = $feature !== null && Money::isPositive($this->raw($feature, 'overage_unit_price') ?? $feature->overage_unit_price);

        if (in_array($plan->pricing_model, ['metered', 'hybrid'], true) && $tiers->isNotEmpty() && ! $featureHasPrice) {
            $amount = Money::round2($this->graduated($tiers, $billable));

            return ['included' => Money::round4($included), 'billable' => Money::round4($billable), 'unit_price' => Money::round4(Money::div($amount, $billable)), 'amount' => $amount];
        }

        $unitPrice = $featureHasPrice
            ? Money::of($this->raw($feature, 'overage_unit_price') ?? $feature->overage_unit_price)
            : Money::of($fallbackUnitPrice);

        return [
            'included' => Money::round4($included),
            'billable' => Money::round4($billable),
            'unit_price' => Money::round4($unitPrice),
            'amount' => Money::round2(Money::mul($billable, $unitPrice)),
        ];
    }

    /**
     * Validates a tier ladder: it starts at 0, the tiers follow each other without gaps or overlaps, and only the last
     * one may be open-ended. Returns the error message, or null when the ladder is valid.
     *
     * @param  array<int, array<string, mixed>>  $tiers
     */
    public function tierLadderError(array $tiers): ?string
    {
        if ($tiers === []) {
            return null;
        }

        usort($tiers, fn (array $a, array $b): int => Money::compare($a['from_quantity'] ?? '0', $b['from_quantity'] ?? '0'));
        $expectedFrom = '0';
        $last = count($tiers) - 1;

        foreach ($tiers as $index => $tier) {
            $from = Money::of($tier['from_quantity'] ?? '0');
            $to = $tier['to_quantity'] ?? null;

            if (Money::compare($from, $expectedFrom) !== 0) {
                return 'The pricing tiers must start at 0 and follow each other without gaps or overlaps.';
            }

            if ($to === null) {
                if ($index !== $last) {
                    return 'Only the last pricing tier may be open-ended.';
                }

                continue;
            }

            if (Money::compare($to, $from) <= 0) {
                return 'Each pricing tier must end above its start.';
            }

            $expectedFrom = Money::of($to);
        }

        return null;
    }

    /**
     * Current raw (uncast) value of an attribute, unsaved changes included: amounts stay decimal strings.
     */
    private function raw(?Model $model, string $key): mixed
    {
        return $model?->getAttributes()[$key] ?? null;
    }

    /**
     * @return Collection<int, PricingTier>
     */
    private function tiers(SubscriptionPlan $plan): Collection
    {
        return $plan->relationLoaded('pricingTiers') ? $plan->pricingTiers : $plan->pricingTiers()->get();
    }

    /**
     * @param  Collection<int, PricingTier>  $tiers
     */
    private function graduated(Collection $tiers, string $quantity): string
    {
        $total = '0';

        foreach ($tiers->sortBy(fn (PricingTier $tier): float => (float) $tier->from_quantity) as $tier) {
            $from = Money::of($this->raw($tier, 'from_quantity'));

            if (Money::compare($quantity, $from) <= 0) {
                break;
            }

            $to = $this->raw($tier, 'to_quantity');
            $upper = $to === null ? $quantity : Money::min($quantity, $to);
            $units = Money::sub($upper, $from);
            $total = Money::add($total, Money::add(Money::mul($units, $this->raw($tier, 'unit_price')), $this->raw($tier, 'flat_fee')));
        }

        return $total;
    }

    /**
     * @param  Collection<int, PricingTier>  $tiers
     */
    private function volume(Collection $tiers, string $quantity): string
    {
        if (Money::isZero($quantity)) {
            return '0';
        }

        foreach ($tiers->sortBy(fn (PricingTier $tier): float => (float) $tier->from_quantity) as $tier) {
            $from = Money::of($this->raw($tier, 'from_quantity'));
            $to = $this->raw($tier, 'to_quantity');

            if (Money::compare($quantity, $from) > 0 && ($to === null || Money::compare($quantity, $to) <= 0)) {
                return Money::add(Money::mul($quantity, $this->raw($tier, 'unit_price')), $this->raw($tier, 'flat_fee'));
            }
        }

        return '0';
    }
}
