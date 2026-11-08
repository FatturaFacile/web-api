<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\Product;
use App\Services\Catalog\PriceResolver;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Sales\VatSummaryCalculator;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Models\CustomerGroup;
use Modules\Invoicing\Models\DiscountPolicy;

final class PricingAndDiscountService implements PricingAndDiscountServiceInterface
{
    public function __construct(
        private readonly PriceResolver $prices = new PriceResolver,
        private readonly VatSummaryCalculator $vat = new VatSummaryCalculator
    ) {}

    /**
     * @return array{unit_price: float, discount_percentage: float, discount_amount: float, final_price: float}
     */
    public function resolvePrice(
        string $productId,
        float $quantity,
        ?string $priceListId = null,
        ?string $customerGroupId = null,
        ?string $discountPolicyId = null
    ): array {
        $basePrice = 0.0;

        // 1. Check price list item with volume tiers
        if ($priceListId !== null) {
            $listPrice = $this->prices->priceInActiveList($priceListId, $productId, $quantity, now());

            if ($listPrice !== null) {
                $basePrice = (float) $listPrice;
            }
        }

        // 2. Fallback to product standard unit price
        if ($basePrice <= 0.0) {
            $product = Product::find($productId);
            if ($product !== null) {
                $basePrice = (float) ($product->sale_price ?? 0.0);
            }
        }

        // 3. Resolve customer group discount
        $discountPercent = 0.0;
        if ($customerGroupId !== null) {
            $group = CustomerGroup::find($customerGroupId);
            if ($group !== null && $group->is_active) {
                $discountPercent += (float) $group->discount_percentage;
            }
        }

        // 4. Resolve discount policy
        $discountAmount = 0.0;
        if ($discountPolicyId !== null) {
            $policy = DiscountPolicy::with('rules')->find($discountPolicyId);
            if ($policy !== null && $policy->is_active) {
                if ($policy->discount_type === 'percentage') {
                    $discountPercent += (float) $policy->value;
                } elseif ($policy->discount_type === 'fixed_amount') {
                    $discountAmount += (float) $policy->value;
                }

                // Check rules for specific product or group
                foreach ($policy->rules as $rule) {
                    if ($rule->product_id === $productId || ($customerGroupId !== null && $rule->customer_group_id === $customerGroupId)) {
                        $discountPercent += (float) $rule->discount_percent;
                        $discountAmount += (float) $rule->discount_fixed_amount;
                    }
                }
            }
        }

        $discountPercent = min(100.0, max(0.0, $discountPercent));
        $percentDiscountAmount = round(($basePrice * $discountPercent) / 100.0, 4);
        $totalDiscountPerUnit = $percentDiscountAmount + $discountAmount;
        $finalPrice = max(0.0, $basePrice - $totalDiscountPerUnit);

        return [
            'unit_price' => round($basePrice, 4),
            'discount_percentage' => round($discountPercent, 2),
            'discount_amount' => round($totalDiscountPerUnit, 4),
            'final_price' => round($finalPrice, 4),
        ];
    }

    /**
     * The tax is computed per VAT rate on the summed net of its lines (see VatSummaryCalculator), never as a sum of
     * rounded line taxes, so it matches the summary of the electronic invoice.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, discount_total: float, net_total: float, tax_total: float, gross_total: float}
     */
    public function calculateOrderTotals(array $items, ?string $discountPolicyId = null): array
    {
        // One computation for every sales document: the decimal one of the totals calculator, so an order, a quote and
        // the invoice of the same lines always show the same line nets and totals.
        $totals = (new DocumentTotalsCalculator($this->vat))->calculateDecimal($items);

        return [
            'subtotal' => (float) $totals['subtotal'],
            'discount_total' => (float) $totals['discount_total'],
            'net_total' => (float) $totals['total_net'],
            'tax_total' => (float) $totals['total_tax'],
            'gross_total' => (float) $totals['total_gross'],
        ];
    }
}
