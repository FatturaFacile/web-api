<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Pricing\Models\PriceListHistory;
use Modules\Pricing\Support\DiscountCascade;
use Modules\Pricing\Support\PriceRounder;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@pricing.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->admin->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->admin);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'WID-1', 'name' => 'Widget', 'cost_price' => 40, 'sale_price' => 100, 'tax_rate' => 22]);
});

function pricingApi(): mixed
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

function pricingList(array $attributes = [], array $items = []): PriceList
{
    $list = PriceList::create(array_merge(['tenant_id' => test()->tenant->id, 'name' => 'List '.Str::random(5), 'currency' => 'EUR', 'is_active' => true], $attributes));

    foreach ($items as $item) {
        $list->items()->create(array_merge(['product_id' => test()->product->id, 'min_quantity' => 1], $item));
    }

    return $list;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pricingResolve(array $overrides = [], array $line = []): array
{
    return pricingApi()->postJson('/api/v1/pricing/resolve', array_merge([
        'currency' => 'EUR',
        'lines' => [array_merge(['product_id' => test()->product->id, 'quantity' => 1], $line)],
    ], $overrides))->assertOk()->json('data');
}

test('the cascade notation chains multiplicatively and adds up additively', function (): void {
    expect(DiscountCascade::parse('20+5')->apply('100')['price'])->toBe('76.0000')
        ->and(DiscountCascade::parse('20+5', DiscountCascade::Additive)->apply('100')['price'])->toBe('75.0000')
        ->and(DiscountCascade::parse('30 + 5 + 2')->notation())->toBe('30+5+2')
        ->and(DiscountCascade::isValid('abc'))->toBeFalse()
        ->and(DiscountCascade::isValid('120'))->toBeFalse()
        ->and(PriceRounder::round('19.3903', PriceRounder::Swiss005))->toBe('19.4000')
        ->and(PriceRounder::round('19.3749', PriceRounder::Swiss005))->toBe('19.3500')
        ->and(PriceRounder::round('10.005', PriceRounder::HalfUp001))->toBe('10.0100');
});

test('the price resolves through the four tiers in order', function (): void {
    // Tier 4: no list at all, the catalogue price.
    $line = pricingResolve()['lines'][0];
    expect($line['net_unit_price'])->toBe('100.0000')->and($line['price_source'])->toBe('base_price');

    // Tier 4: the default price list.
    pricingList(['is_default' => true], [['price' => 95]]);
    expect(pricingResolve()['lines'][0]['net_unit_price'])->toBe('95.0000');

    // Tier 3: the list of the sales channel beats the default list.
    pricingList(['channel' => 'B2B', 'priority' => 5], [['price' => 90]]);
    $line = pricingResolve(['channel' => 'B2B'])['lines'][0];
    expect($line['net_unit_price'])->toBe('90.0000')->and($line['price_source'])->toBe('channel_price_list');

    // Tier 2: the list assigned to the customer beats the channel list.
    $customerList = pricingList([], [['price' => 80]]);
    $this->contact->update(['price_list_id' => $customerList->id]);
    $line = pricingResolve(['channel' => 'B2B', 'customer_id' => $this->contact->id])['lines'][0];
    expect($line['net_unit_price'])->toBe('80.0000')->and($line['price_source'])->toBe('customer_price_list');

    // Tier 1: the special price of the customer beats everything, while it is valid.
    pricingApi()->postJson('/api/v1/pricing/special-prices', [
        'customer_id' => $this->contact->id, 'product_id' => $this->product->id, 'currency' => 'EUR',
        'fixed_price' => 70, 'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31',
    ])->assertCreated();
    $resolved = pricingResolve(['channel' => 'B2B', 'customer_id' => $this->contact->id]);
    expect($resolved['lines'][0]['net_unit_price'])->toBe('70.0000')->and($resolved['lines'][0]['price_source'])->toBe('customer_special_price');

    // Out of its validity the special price steps aside; a special price in another currency never applies.
    $this->travelTo('2026-04-02 10:00:00');
    expect(pricingResolve(['channel' => 'B2B', 'customer_id' => $this->contact->id])['lines'][0]['net_unit_price'])->toBe('80.0000');
});

test('item cascades, manual cascades and quantity tiers set the net price', function (): void {
    pricingList(['is_default' => true], [
        ['price' => 100, 'discount_cascade' => '20+5', 'discount_mode' => 'MULTIPLICATIVE'],
        ['price' => 100, 'min_quantity' => 10, 'max_quantity' => 49, 'discount_cascade' => '20+5', 'discount_mode' => 'ADDITIVE'],
        ['price' => 90, 'min_quantity' => 50],
    ]);

    $line = pricingResolve()['lines'][0];
    expect($line['net_unit_price'])->toBe('76.0000')->and($line['discount_cascade'])->toBe('20+5')->and($line['discount_percentage'])->toBe('24.00');

    $line = pricingResolve([], ['quantity' => 10])['lines'][0];
    expect($line['net_unit_price'])->toBe('75.0000')->and($line['line_total'])->toBe('750.0000');

    expect(pricingResolve([], ['quantity' => 50])['lines'][0]['net_unit_price'])->toBe('90.0000');

    // The manual cascade of the line is applied after the list cascade.
    $line = pricingResolve([], ['manual_discount_cascade' => '10'])['lines'][0];
    expect($line['net_unit_price'])->toBe('68.4000')->and(collect($line['trace'])->pluck('rule')->all())->toContain('discount_cascade', 'manual_discount_cascade');
});

test('Swiss 0.05 rounding applies to a CHF price list and reports the delta', function (): void {
    $chfProduct = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'CHF-1', 'name' => 'Swiss item', 'cost_price' => 5, 'sale_price' => 19.99, 'tax_rate' => 8]);
    pricingList(['currency' => 'CHF', 'is_default' => true, 'rounding_mode' => 'SWISS_005'], [['product_id' => $chfProduct->id, 'price' => 19.99, 'discount_cascade' => '3']]);

    $line = pricingResolve(['currency' => 'CHF'], ['product_id' => $chfProduct->id])['lines'][0];

    expect($line['net_unit_price'])->toBe('19.4000')
        ->and($line['rounding_mode'])->toBe('SWISS_005')
        ->and($line['rounding_delta'])->toBe('0.0097');
});

test('a 3x2 promotion is time bounded', function (): void {
    pricingApi()->postJson('/api/v1/pricing/promotions', [
        'code' => '3X2', 'name' => 'Three for two', 'type' => 'BUY_X_GET_Y',
        'rules' => ['product_id' => $this->product->id, 'buy_qty' => 3, 'pay_qty' => 2],
        'valid_from' => '2026-03-01', 'valid_to' => '2026-03-31',
    ])->assertCreated();

    $resolved = pricingResolve([], ['quantity' => 3]);
    expect($resolved['lines'][0]['promotion_discount'])->toBe('100.0000')
        ->and($resolved['lines'][0]['line_net_total'])->toBe('200.0000')
        ->and($resolved['applied_promotions'][0]['code'])->toBe('3X2')
        ->and($resolved['total'])->toBe('200.0000');

    // Two units are not enough.
    expect(pricingResolve([], ['quantity' => 2])['applied_promotions'])->toBe([]);

    // After the promotion ends it no longer applies.
    $this->travelTo('2026-04-02 10:00:00');
    expect(pricingResolve([], ['quantity' => 3])['applied_promotions'])->toBe([]);

    pricingApi()->postJson('/api/v1/pricing/promotions', ['code' => 'BAD', 'name' => 'Bad', 'type' => 'BUY_X_GET_Y', 'rules' => ['buy_qty' => 2, 'pay_qty' => 2]])
        ->assertUnprocessable();
});

test('free gifts carry the VAT recourse treatment of the promotion', function (): void {
    $gift = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'GIFT-1', 'name' => 'Mug', 'cost_price' => 2, 'sale_price' => 10, 'tax_rate' => 22]);

    foreach (['WITH_VAT_RECOURSE' => '2.2000', 'WITHOUT_VAT_RECOURSE' => '0.0000'] as $treatment => $recourse) {
        $created = pricingApi()->postJson('/api/v1/pricing/promotions', [
            'code' => 'GIFT-'.$treatment, 'name' => 'Free mug', 'type' => 'FREE_GIFT',
            'rules' => ['trigger_min_amount' => 100, 'gift_product_id' => $gift->id, 'gift_quantity' => 1, 'vat_treatment' => $treatment],
        ])->assertCreated();

        $gifts = pricingResolve()['gifts'];
        expect($gifts)->toHaveCount(1)
            ->and($gifts[0]['vat_treatment'])->toBe($treatment)
            ->and($gifts[0]['vat_recourse_amount'])->toBe($recourse)
            ->and($gifts[0]['unit_price'])->toBe('0.0000');

        // Below the trigger amount there is no gift.
        expect(pricingResolve([], ['quantity' => 1, 'manual_discount_cascade' => '60'])['gifts'])->toBe([]);

        pricingApi()->deleteJson('/api/v1/pricing/promotions/'.$created->json('data.id'))->assertNoContent();
    }
});

test('a margin below the policy blocks the line, a zero price is handled and a supervisor override unlocks the order', function (): void {
    pricingApi()->postJson('/api/v1/pricing/margin-policies', ['scope' => 'GLOBAL', 'min_margin_percent' => 30, 'block_on_violation' => true])->assertCreated();

    $ok = pricingResolve()['lines'][0];
    expect($ok['margin_percent'])->toBe('60.00')->and($ok['margin_status'])->toBe('OK');

    $warning = pricingResolve([], ['manual_discount_cascade' => '40'])['lines'][0]; // 60 -> margin 33.33
    expect($warning['margin_status'])->toBe('WARNING');

    $resolved = pricingResolve([], ['manual_discount_cascade' => '50']); // 50 -> margin 20
    expect($resolved['lines'][0]['margin_percent'])->toBe('20.00')->and($resolved['margin']['status'])->toBe('BLOCKED');

    pricingApi()->postJson('/api/v1/pricing/special-prices', ['customer_id' => $this->contact->id, 'product_id' => $this->product->id, 'currency' => 'EUR', 'fixed_price' => 0])->assertCreated();
    $free = pricingResolve(['customer_id' => $this->contact->id])['lines'][0];
    expect($free['margin_percent'])->toBeNull()->and($free['margin_status'])->toBe('BLOCKED');

    // A draft order sold at 50 against a cost of 40 cannot be confirmed.
    $order = pricingApi()->postJson('/api/v1/sales/orders', [
        'contact_id' => $this->contact->id, 'date' => '2026-03-15', 'currency' => 'EUR', 'status' => 'draft',
        'items' => [['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 22]],
    ])->assertCreated()->json('data');

    pricingApi()->postJson("/api/v1/sales/orders/{$order['id']}/confirm")->assertUnprocessable();
    pricingApi()->getJson("/api/v1/pricing/margin-overrides/check/sales_order/{$order['id']}")->assertOk()->assertJsonPath('data.status', 'BLOCKED');

    // A member without the supervisor role cannot authorize it.
    $manager = User::create(['name' => 'Manager', 'email' => 'manager@pricing.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $manager->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'sales_manager']);
    pricingApi()->postJson('/api/v1/pricing/margin-policies', ['scope' => 'PRODUCT', 'scope_id' => $this->product->id, 'min_margin_percent' => 30, 'requires_approval_role' => 'owner'])->assertCreated();
    Sanctum::actingAs($manager);
    pricingApi()->postJson('/api/v1/pricing/margin-overrides/approve', ['document_type' => 'sales_order', 'document_id' => $order['id']])->assertForbidden();

    // The owner of the tenant authorizes it, and the order can then be confirmed.
    $owner = User::create(['name' => 'Owner', 'email' => 'owner@pricing.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $owner->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);
    Sanctum::actingAs($owner);
    pricingApi()->postJson('/api/v1/pricing/margin-overrides/approve', ['document_type' => 'sales_order', 'document_id' => $order['id'], 'notes' => 'Strategic customer'])
        ->assertCreated()
        ->assertJsonPath('data.calculated_margin_percent', '20.00')
        ->assertJsonPath('data.threshold_percent', '30.00')
        ->assertJsonPath('data.approved_by_user_id', (string) $owner->id);

    pricingApi()->postJson("/api/v1/sales/orders/{$order['id']}/confirm")->assertOk();
});

test('price changes are recorded in an immutable history', function (): void {
    $list = pricingList(['is_default' => true]);

    $item = pricingApi()->postJson("/api/v1/pricing/price-lists/{$list->id}/items", [
        'product_id' => $this->product->id, 'price' => 100, 'discount_cascade' => '10', 'reason' => 'Initial price',
    ])->assertCreated()->json('data');

    pricingApi()->putJson("/api/v1/pricing/price-lists/{$list->id}/items/{$item['id']}", ['price' => 110, 'discount_cascade' => '10+5', 'reason' => 'Supplier increase'])->assertOk();
    // Nothing changed: no history row.
    pricingApi()->putJson("/api/v1/pricing/price-lists/{$list->id}/items/{$item['id']}", ['price' => 110])->assertOk();

    $history = pricingApi()->getJson("/api/v1/pricing/price-history?price_list_id={$list->id}")->assertOk();
    expect($history->json('meta.total'))->toBe(2);

    $latest = $history->json('data.0');
    expect($latest['old_price'])->toBe('100.0000')
        ->and($latest['new_price'])->toBe('110.0000')
        ->and($latest['old_discount_cascade'])->toBe('10')
        ->and($latest['new_discount_cascade'])->toBe('10+5')
        ->and($latest['reason'])->toBe('Supplier increase')
        ->and($latest['changed_by_user_id'])->toBe((string) $this->admin->id)
        ->and($latest['changed_by_name'])->toBe('Admin');

    $row = PriceListHistory::query()->firstOrFail();
    expect(fn () => $row->update(['new_price' => 1]))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);

    // The bulk import creates and updates by product and quantity break.
    $bulk = pricingApi()->postJson("/api/v1/pricing/price-lists/{$list->id}/items/bulk", ['items' => [
        ['product_id' => $this->product->id, 'price' => 120],
        ['product_id' => $this->product->id, 'price' => 100, 'min_quantity' => 10],
    ]])->assertOk();
    expect($bulk->json('data'))->toBe(['created' => 1, 'updated' => 1]);
});

test('pricing records are isolated per tenant', function (): void {
    $special = pricingApi()->postJson('/api/v1/pricing/special-prices', [
        'customer_id' => $this->contact->id, 'product_id' => $this->product->id, 'currency' => 'EUR', 'fixed_price' => 70,
    ])->assertCreated()->json('data');
    pricingApi()->postJson('/api/v1/pricing/promotions', ['code' => 'P1', 'name' => 'Promo', 'type' => 'CART_VALUE_TIER', 'rules' => ['tiers' => [['min_amount' => 100, 'discount_percent' => 5]]]])->assertCreated();

    $other = Tenant::create(['name' => 'Other SA', 'legal_name' => 'Other SA', 'vat_number' => '01234567897', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@pricing.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($stranger);
    $asOther = fn () => test()->withHeader('X-Tenant-Id', $other->id);

    expect($asOther()->getJson('/api/v1/pricing/special-prices')->assertOk()->json('meta.total'))->toBe(0)
        ->and($asOther()->getJson('/api/v1/pricing/promotions')->assertOk()->json('meta.total'))->toBe(0);
    $asOther()->getJson('/api/v1/pricing/special-prices/'.$special['id'])->assertNotFound();

    // The same promotion code is free in the other tenant, and a foreign product or customer is refused.
    $asOther()->postJson('/api/v1/pricing/promotions', ['code' => 'P1', 'name' => 'Promo', 'type' => 'CART_VALUE_TIER', 'rules' => ['tiers' => [['min_amount' => 100, 'discount_percent' => 5]]]])->assertCreated();
    $asOther()->postJson('/api/v1/pricing/special-prices', ['customer_id' => $this->contact->id, 'product_id' => $this->product->id, 'currency' => 'EUR', 'fixed_price' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors(['customer_id', 'product_id']);
    $asOther()->postJson('/api/v1/pricing/resolve', ['currency' => 'EUR', 'lines' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertUnprocessable();
});
