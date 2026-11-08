<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\Contact;
use App\Models\Product;
use App\Services\Catalog\PriceResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\DiscountPolicy;
use Modules\Invoicing\Models\DiscountPolicyRule;

/**
 * Price determination of a sales line (the condition technique of SAP SD, reduced to what the catalogue models):
 *
 * 1. List price: a price list named on the document, otherwise the price list of the customer, otherwise the default
 *    list of the company, otherwise the sale price of the product. Lists and their items have a validity period, items
 *    have quantity breaks (the highest minimum quantity not above the line quantity wins) and a list without the product
 *    falls back on its parent list moved by its percentage (see PriceResolver). A customer-specific price is a price
 *    list assigned to that customer.
 * 2. Discounts: the discount policy named on the document plus every active `auto_apply` promotion valid on the date,
 *    in priority order (lower number first). A promotion can be limited to one customer, to a minimum quantity of the
 *    line and to a minimum order amount; a promotion with rules applies only through the rules that match the product,
 *    the customer or the customer group, each adding its own discount. A promotion that is not stackable applies only
 *    when it is the first one to apply, and then stops the others.
 *
 * Percentages add up (capped at 100). Fixed discounts are amounts per unit and lower the unit price; the percentage then
 * applies to the lowered price. All amounts are decimal strings: the unit price with 4 decimals, the percentage with 2,
 * as the document lines store them.
 */
final readonly class SalesPriceEngine
{
    public function __construct(private PriceResolver $prices) {}

    /**
     * @return array{
     *     list_price: string,
     *     price_source: string,
     *     price_list_id: ?string,
     *     unit_price: string,
     *     discount_percentage: string,
     *     fixed_discount_per_unit: string,
     *     net_unit_price: string,
     *     applied_discounts: list<array{discount_policy_id: string, code: ?string, name: ?string, priority: int, percentage: string, fixed_amount_per_unit: string, explicit: bool}>
     * }
     */
    public function determine(
        Product $product,
        ?Contact $contact,
        string $quantity,
        CarbonInterface $date,
        ?string $priceListId = null,
        ?string $discountPolicyId = null,
        ?string $customerGroupId = null,
        ?string $orderAmount = null,
    ): array {
        [$listPrice, $source, $listId] = $this->listPrice($product, $contact, $quantity, $date, $priceListId);

        $percentage = '0';
        $fixed = '0';
        $applied = [];

        foreach ($this->candidatePolicies($contact, $date, $discountPolicyId) as $policy) {
            $explicit = $discountPolicyId !== null && (string) $policy->id === $discountPolicyId;
            $contribution = $this->contribution($policy, $product, $contact, $customerGroupId, $quantity, $orderAmount, $explicit);

            if ($contribution === null) {
                continue;
            }

            if (! $policy->is_stackable && $applied !== []) {
                continue;
            }

            $percentage = Decimal::add($percentage, $contribution[0]);
            $fixed = Decimal::add($fixed, $contribution[1]);
            $applied[] = [
                'discount_policy_id' => (string) $policy->id,
                'code' => $policy->code,
                'name' => $policy->name,
                'priority' => (int) $policy->priority,
                'percentage' => bcadd($contribution[0], '0', 2),
                'fixed_amount_per_unit' => bcadd($contribution[1], '0', 4),
                'explicit' => $explicit,
            ];

            if (! $policy->is_stackable) {
                break;
            }
        }

        if (Decimal::compare($percentage, '100') > 0) {
            $percentage = '100';
        }

        $unitPrice = Decimal::sub($listPrice, $fixed);

        if (Decimal::isNegative($unitPrice)) {
            $unitPrice = '0';
        }

        $unitPrice = bcadd($unitPrice, '0', 4);
        $percentage = bcadd($percentage, '0', 2);
        $net = Decimal::mul($unitPrice, Decimal::sub('1', Decimal::div($percentage, '100')));

        return [
            'list_price' => bcadd($listPrice, '0', 4),
            'price_source' => $source,
            'price_list_id' => $listId,
            'unit_price' => $unitPrice,
            'discount_percentage' => $percentage,
            'fixed_discount_per_unit' => bcadd(Decimal::sub($listPrice, $unitPrice), '0', 4),
            'net_unit_price' => bcadd($net, '0', 4),
            'applied_discounts' => $applied,
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function listPrice(Product $product, ?Contact $contact, string $quantity, CarbonInterface $date, ?string $priceListId): array
    {
        if ($priceListId !== null) {
            $price = $this->prices->priceInActiveList($priceListId, (string) $product->id, (float) $quantity, $date);

            if ($price !== null) {
                return [$price, 'price_list', $priceListId];
            }
        }

        $resolved = $this->prices->resolveDecimal($product, $contact, (float) $quantity, $date);

        return [$resolved['price'], $resolved['source'], $resolved['price_list_id']];
    }

    /**
     * The named policy (when active) and the promotions that apply by themselves, valid on the date and open to the
     * customer, in priority order.
     *
     * @return Collection<int, DiscountPolicy>
     */
    private function candidatePolicies(?Contact $contact, CarbonInterface $date, ?string $discountPolicyId): Collection
    {
        $day = $date->toDateString();

        return DiscountPolicy::query()
            ->with('rules')
            ->where('is_active', true)
            ->where(static fn ($query) => $query->where('auto_apply', true)->when($discountPolicyId !== null, static fn ($inner) => $inner->orWhere('id', $discountPolicyId)))
            ->where(static fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(static fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))
            ->where(static fn ($query) => $query->whereNull('contact_id')->when($contact !== null, static fn ($inner) => $inner->orWhere('contact_id', $contact?->id)))
            ->orderBy('priority')
            ->orderBy('code')
            ->get();
    }

    /**
     * What a policy gives the line: [percentage, fixed amount per unit], or null when it does not apply.
     *
     * @return array{0: string, 1: string}|null
     */
    private function contribution(DiscountPolicy $policy, Product $product, ?Contact $contact, ?string $customerGroupId, string $quantity, ?string $orderAmount, bool $explicit): ?array
    {
        $minimumQuantity = $policy->getAttribute('min_quantity');

        if ($minimumQuantity !== null && Decimal::compare($quantity, DocumentTotalsCalculator::decimal($minimumQuantity)) < 0) {
            return null;
        }

        $minimumOrder = DocumentTotalsCalculator::decimal($policy->min_order_amount);

        if (Decimal::isPositive($minimumOrder) && ($orderAmount === null || Decimal::compare($orderAmount, $minimumOrder) < 0)) {
            return null;
        }

        $matching = $policy->rules->filter(static fn (DiscountPolicyRule $rule): bool => ($rule->product_id === null || (string) $rule->product_id === (string) $product->id)
            && ($rule->customer_group_id === null || ($customerGroupId !== null && (string) $rule->customer_group_id === $customerGroupId))
            && ($rule->contact_id === null || ($contact !== null && (string) $rule->contact_id === (string) $contact->id))
            && ($rule->product_id !== null || $rule->customer_group_id !== null || $rule->contact_id !== null)
            && Decimal::compare($quantity, DocumentTotalsCalculator::decimal($rule->min_threshold)) >= 0);

        // A promotion with rules applies through them; the policy named on the document keeps its header discount.
        if ($policy->rules->isNotEmpty() && $matching->isEmpty() && ! $explicit) {
            return null;
        }

        $percentage = '0';
        $fixed = '0';
        $value = DocumentTotalsCalculator::decimal($policy->value);

        if ($policy->discount_type === 'percentage') {
            $percentage = $value;
        } elseif ($policy->discount_type === 'fixed_amount') {
            $fixed = $value;
        }

        foreach ($matching as $rule) {
            $percentage = Decimal::add($percentage, DocumentTotalsCalculator::decimal($rule->discount_percent));
            $fixed = Decimal::add($fixed, DocumentTotalsCalculator::decimal($rule->discount_fixed_amount));
        }

        return [$percentage, $fixed];
    }
}
