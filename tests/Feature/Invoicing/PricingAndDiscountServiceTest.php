<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;
use Modules\Invoicing\Models\DiscountPolicy;

uses(RefreshDatabase::class);

/**
 * Prices and totals of the invoicing module: list price with quantity breaks, the fixed and percentage discounts of the
 * policy named on the document, the totals per VAT rate. Expected values are computed by hand in each test.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Pricing '.Str::random(4), 'legal_name' => 'Pricing SA', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'P-'.Str::random(5), 'name' => 'Widget', 'sale_price' => 100, 'cost_price' => 60, 'tax_rate' => 22.0]);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'client', 'company_name' => 'Cliente', 'country' => 'IT']);
    $this->service = app(PricingAndDiscountServiceInterface::class);
});

function pricingList(): PriceList
{
    $list = PriceList::create(['name' => 'Base '.Str::random(3), 'currency' => 'EUR', 'is_active' => true]);
    $list->items()->create(['product_id' => test()->product->id, 'price' => 40, 'min_quantity' => 1]);
    $list->items()->create(['product_id' => test()->product->id, 'price' => 35, 'min_quantity' => 10]);

    return $list;
}

function pricingPolicy(array $attributes): DiscountPolicy
{
    return DiscountPolicy::create([
        'tenant_id' => test()->tenant->id, 'code' => 'D'.Str::random(4), 'name' => 'Policy', 'discount_type' => 'percentage', 'value' => 0,
        'is_active' => true, 'is_stackable' => true, 'auto_apply' => false, ...$attributes,
    ]);
}

test('an unknown product has no price', function (): void {
    expect($this->service->resolvePrice((string) Str::uuid7(), 1.0))->toBe(['unit_price' => 0.0, 'discount_percentage' => 0.0, 'discount_amount' => 0.0, 'final_price' => 0.0]);
});

test('without a price list the price is the sale price of the product', function (): void {
    expect($this->service->resolvePrice((string) $this->product->id, 3.0))->toBe(['unit_price' => 100.0, 'discount_percentage' => 0.0, 'discount_amount' => 0.0, 'final_price' => 100.0]);
});

test('a price list gives the price of the highest quantity break not above the quantity', function (int $quantity, float $expected): void {
    $list = pricingList();

    expect($this->service->resolvePrice((string) $this->product->id, (float) $quantity, (string) $list->id)['unit_price'])->toBe($expected);
})->with([
    'one unit' => [1, 40.0],
    'just below the break' => [9, 40.0],
    'at the break' => [10, 35.0],
    'above the break' => [250, 35.0],
]);

test('a product missing from the named list falls back on the sale price', function (): void {
    $empty = PriceList::create(['name' => 'Empty', 'currency' => 'EUR', 'is_active' => true]);

    expect($this->service->resolvePrice((string) $this->product->id, 1.0, (string) $empty->id)['unit_price'])->toBe(100.0);
});

test('the policy named on the line gives its percentage on the list price', function (): void {
    $policy = pricingPolicy(['value' => 15]);

    $line = $this->service->determineLinePrice((string) $this->product->id, '2', (string) $this->customer->id, null, (string) $policy->id, '2026-03-01');

    // 100 less 15% = 85.
    expect($line['list_price'])->toBe('100.0000')
        ->and($line['discount_percentage'])->toBe('15.00')
        ->and($line['net_unit_price'])->toBe('85.0000')
        ->and($line['applied_discounts'])->toHaveCount(1)
        ->and($line['applied_discounts'][0]['explicit'])->toBeTrue();
});

test('a fixed discount lowers the unit price and the percentage applies to the lowered price', function (): void {
    $fixed = pricingPolicy(['discount_type' => 'fixed_amount', 'value' => 10, 'auto_apply' => true, 'priority' => 1]);
    $percent = pricingPolicy(['value' => 10, 'auto_apply' => true, 'priority' => 2]);

    $line = $this->service->determineLinePrice((string) $this->product->id, '1', null, null, null, '2026-03-01');

    // 100 - 10 = 90; 90 less 10% = 81.
    expect($line['unit_price'])->toBe('90.0000')
        ->and($line['fixed_discount_per_unit'])->toBe('10.0000')
        ->and($line['discount_percentage'])->toBe('10.00')
        ->and($line['net_unit_price'])->toBe('81.0000')
        ->and(array_column($line['applied_discounts'], 'discount_policy_id'))->toBe([(string) $fixed->id, (string) $percent->id]);
});

test('a promotion that is not stackable stops the ones after it, and an inactive or expired one is ignored', function (): void {
    pricingPolicy(['value' => 20, 'auto_apply' => true, 'priority' => 1, 'is_stackable' => false]);
    pricingPolicy(['value' => 5, 'auto_apply' => true, 'priority' => 2]);
    pricingPolicy(['value' => 50, 'auto_apply' => true, 'priority' => 0, 'is_active' => false]);
    pricingPolicy(['value' => 50, 'auto_apply' => true, 'priority' => 0, 'valid_to' => '2026-01-31']);

    $line = $this->service->determineLinePrice((string) $this->product->id, '1', null, null, null, '2026-03-01');

    expect($line['discount_percentage'])->toBe('20.00')
        ->and($line['net_unit_price'])->toBe('80.0000')
        ->and($line['applied_discounts'])->toHaveCount(1);
});

test('minimum quantity and minimum order amount decide whether a promotion applies', function (): void {
    pricingPolicy(['value' => 10, 'auto_apply' => true, 'min_quantity' => 5, 'min_order_amount' => 1000]);
    $id = (string) $this->product->id;

    $none = $this->service->determineLinePrice($id, '4', null, null, null, '2026-03-01', '2000');
    $lowOrder = $this->service->determineLinePrice($id, '5', null, null, null, '2026-03-01', '999.99');
    $noOrder = $this->service->determineLinePrice($id, '5', null, null, null, '2026-03-01');
    $both = $this->service->determineLinePrice($id, '5', null, null, null, '2026-03-01', '1000');

    expect($none['discount_percentage'])->toBe('0.00')
        ->and($lowOrder['discount_percentage'])->toBe('0.00')
        ->and($noOrder['discount_percentage'])->toBe('0.00')
        ->and($both['discount_percentage'])->toBe('10.00');
});

test('percentages add up but never go above 100', function (): void {
    pricingPolicy(['value' => 70, 'auto_apply' => true, 'priority' => 1]);
    pricingPolicy(['value' => 60, 'auto_apply' => true, 'priority' => 2]);

    $line = $this->service->determineLinePrice((string) $this->product->id, '1', null, null, null, '2026-03-01');

    expect($line['discount_percentage'])->toBe('100.00')
        ->and($line['net_unit_price'])->toBe('0.0000');
});

test('a fixed discount larger than the price leaves a price of zero, not a negative one', function (): void {
    pricingPolicy(['discount_type' => 'fixed_amount', 'value' => 150, 'auto_apply' => true]);

    $line = $this->service->determineLinePrice((string) $this->product->id, '1', null, null, null, '2026-03-01');

    expect($line['unit_price'])->toBe('0.0000')
        ->and($line['net_unit_price'])->toBe('0.0000');
});

test('the order totals take the VAT per rate on the summed net, not as a sum of rounded line taxes', function (): void {
    // Three lines of 33.33 at 22%: net 99.99, VAT on the sum 21.9978 -> 22.00 (a sum of rounded line taxes would give 21.99).
    $totals = $this->service->calculateOrderTotals([
        ['quantity' => 1, 'unit_price' => 33.33, 'tax_rate' => 22],
        ['quantity' => 1, 'unit_price' => 33.33, 'tax_rate' => 22],
        ['quantity' => 1, 'unit_price' => 33.33, 'tax_rate' => 22],
    ]);

    expect($totals['net_total'])->toBe(99.99)
        ->and($totals['tax_total'])->toBe(22.0)
        ->and($totals['gross_total'])->toBe(121.99);
});

test('discounts reduce the net of the lines before the VAT', function (): void {
    // 2 x 100 less 10% = 180 at 22% -> 39.60; 1 x 50 at 10% -> 5.00.
    $totals = $this->service->calculateOrderTotals([
        ['quantity' => 2, 'unit_price' => 100, 'tax_rate' => 22, 'discount_percentage' => 10],
        ['quantity' => 1, 'unit_price' => 50, 'tax_rate' => 10],
    ]);

    expect($totals['subtotal'])->toBe(250.0)
        ->and($totals['discount_total'])->toBe(20.0)
        ->and($totals['net_total'])->toBe(230.0)
        ->and($totals['tax_total'])->toBe(44.6)
        ->and($totals['gross_total'])->toBe(274.6);
});
