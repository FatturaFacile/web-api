<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\Product;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Models\CustomerGroup;
use Modules\Invoicing\Models\DiscountPolicy;
use Modules\Invoicing\Models\SalesPriceListItem;

final class PricingAndDiscountService implements PricingAndDiscountServiceInterface
{
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
            $priceListItem = SalesPriceListItem::where('price_list_id', $priceListId)
                ->where('product_id', $productId)
                ->where('min_quantity', '<=', $quantity)
                ->orderBy('min_quantity', 'desc')
                ->first();

            if ($priceListItem !== null) {
                $basePrice = (float) $priceListItem->price;
            }
        }

        // 2. Fallback to product standard unit price
        if ($basePrice <= 0.0) {
            $product = Product::find($productId);
            if ($product !== null) {
                $basePrice = (float) ($product->price ?? $product->unit_price ?? 0.0);
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
     * @param array<int, array<string, mixed>> $items
     * @return array{subtotal: float, discount_total: float, net_total: float, tax_total: float, gross_total: float}
     */
    public function calculateOrderTotals(array $items, ?string $discountPolicyId = null): array
    {
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($items as $item) {
            $qty = (float) ($item['quantity'] ?? 1.0);
            $unitPrice = (float) ($item['unit_price'] ?? 0.0);
            $discountPct = (float) ($item['discount_percentage'] ?? 0.0);
            $taxRate = (float) ($item['tax_rate'] ?? 0.0);

            $lineRaw = $qty * $unitPrice;
            $lineDiscount = round(($lineRaw * $discountPct) / 100.0, 2);
            $lineNet = round($lineRaw - $lineDiscount, 2);
            $lineTax = round(($lineNet * $taxRate) / 100.0, 2);

            $subtotal += $lineRaw;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;
        }

        $netTotal = round($subtotal - $discountTotal, 2);
        $grossTotal = round($netTotal + $taxTotal, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'net_total' => round($netTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'gross_total' => round($grossTotal, 2),
        ];
    }
}
