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
     * Price of a document line for a customer on a date (price lists, customer list, promotions by priority), as
     * decimal strings.
     *
     * @return array{list_price: string, price_source: string, price_list_id: ?string, unit_price: string, discount_percentage: string, fixed_discount_per_unit: string, net_unit_price: string, applied_discounts: list<array{discount_policy_id: string, code: ?string, name: ?string, priority: int, percentage: string, fixed_amount_per_unit: string, explicit: bool}>}
     */
    public function determineLinePrice(string $productId, string $quantity, ?string $contactId, ?string $priceListId = null, ?string $discountPolicyId = null, ?string $date = null, ?string $orderAmount = null): array;

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{subtotal: float, discount_total: float, net_total: float, tax_total: float, gross_total: float}
     */
    public function calculateOrderTotals(array $items, ?string $discountPolicyId = null): array;
}
