<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalog\PriceResolver;
use App\Services\Sales\DocumentTotalsCalculator;
use App\Services\Sales\SalesPriceEngine;
use App\Services\Sales\VatSummaryCalculator;
use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;

/**
 * Prices and totals of the sales documents of the invoicing module. The price of a line comes from the price
 * determination of the sales cycle (SalesPriceEngine: price lists with validity and quantity breaks, customer lists,
 * promotions by priority); totals from the decimal totals calculator shared by every sales document.
 */
final class PricingAndDiscountService implements PricingAndDiscountServiceInterface
{
    public function __construct(
        private readonly PriceResolver $prices = new PriceResolver,
        private readonly VatSummaryCalculator $vat = new VatSummaryCalculator,
        private readonly ?SalesPriceEngine $engine = null,
        private readonly ?TenantClock $clock = null,
    ) {}

    /**
     * Price of a product for a quantity, without a customer, as floats for the callers that still read them.
     *
     * @return array{unit_price: float, discount_percentage: float, discount_amount: float, final_price: float}
     */
    public function resolvePrice(
        string $productId,
        float $quantity,
        ?string $priceListId = null,
        ?string $customerGroupId = null,
        ?string $discountPolicyId = null
    ): array {
        $product = Product::query()->find($productId);

        if ($product === null) {
            return ['unit_price' => 0.0, 'discount_percentage' => 0.0, 'discount_amount' => 0.0, 'final_price' => 0.0];
        }

        $price = $this->engine()->determine($product, null, DocumentTotalsCalculator::decimal($quantity), $this->today((string) $product->tenant_id), $priceListId, $discountPolicyId, $customerGroupId);
        $discountPerUnit = Decimal::sub($price['list_price'], $price['net_unit_price']);

        return [
            'unit_price' => (float) $price['list_price'],
            'discount_percentage' => (float) $price['discount_percentage'],
            'discount_amount' => (float) bcadd($discountPerUnit, '0', 4),
            'final_price' => (float) $price['net_unit_price'],
        ];
    }

    /**
     * Price of a document line for a customer on a date: unit price (list price less the fixed discounts per unit) and
     * discount percentage as the line stores them, with the discounts that were applied.
     *
     * @return array{list_price: string, price_source: string, price_list_id: ?string, unit_price: string, discount_percentage: string, fixed_discount_per_unit: string, net_unit_price: string, applied_discounts: list<array{discount_policy_id: string, code: ?string, name: ?string, priority: int, percentage: string, fixed_amount_per_unit: string, explicit: bool}>}
     */
    public function determineLinePrice(string $productId, string $quantity, ?string $contactId, ?string $priceListId = null, ?string $discountPolicyId = null, ?string $date = null, ?string $orderAmount = null): array
    {
        $product = Product::query()->findOrFail($productId);
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;
        $day = $date !== null ? CarbonImmutable::parse($date) : $this->today((string) $product->tenant_id);

        return $this->engine()->determine($product, $contact, $quantity, $day, $priceListId, $discountPolicyId, null, $orderAmount);
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
        $totals = (new DocumentTotalsCalculator($this->vat))->calculateDecimal($items);

        return [
            'subtotal' => (float) $totals['subtotal'],
            'discount_total' => (float) $totals['discount_total'],
            'net_total' => (float) $totals['total_net'],
            'tax_total' => (float) $totals['total_tax'],
            'gross_total' => (float) $totals['total_gross'],
        ];
    }

    private function engine(): SalesPriceEngine
    {
        return $this->engine ?? new SalesPriceEngine($this->prices);
    }

    private function today(string $tenantId): CarbonImmutable
    {
        return ($this->clock ?? app(TenantClock::class))->today(Tenant::query()->find($tenantId));
    }
}
