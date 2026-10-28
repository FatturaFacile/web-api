<?php

declare(strict_types=1);

namespace Modules\Invoicing\Contracts;

interface PricingAndDiscountServiceInterface
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
    ): array;

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array{subtotal: float, discount_total: float, net_total: float, tax_total: float, gross_total: float}
     */
    public function calculateOrderTotals(array $items, ?string $discountPolicyId = null): array;
}
