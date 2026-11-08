<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;

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
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@sales.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->admin->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->admin);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT']);
    $this->warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $this->product = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'WID-1', 'name' => 'Widget', 'cost_price' => 40, 'sale_price' => 100,
        'tax_rate' => 22, 'track_inventory' => true,
    ]);

    salesCycleApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->product->id, 'quantity' => 10])->assertCreated();
});

function salesCycleApi(): mixed
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

/**
 * Acts as a member of the test company with the given role.
 */
function salesCycleActAs(string $role): User
{
    $user = User::create(['name' => ucfirst($role), 'email' => $role.'-'.Str::random(6).'@sales.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach(test()->tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    Sanctum::actingAs($user);

    return $user;
}

function salesCycleStock(): array
{
    return collect(salesCycleApi()->getJson('/api/v1/inventory/stock-levels')->assertOk()->json('data'))->firstWhere('product_id', test()->product->id) ?? [];
}

/**
 * A confirmed order of the widget for the test customer.
 */
function salesCycleOrder(float|int $quantity = 15, array $extra = []): array
{
    return salesCycleApi()->postJson('/api/v1/sales/orders', array_merge([
        'contact_id' => test()->contact->id,
        'date' => '2026-03-15',
        'currency' => 'EUR',
        'status' => 'confirmed',
        'items' => [['product_id' => test()->product->id, 'description' => 'Widget', 'quantity' => $quantity, 'unit_price' => 100, 'tax_rate' => 22]],
    ], $extra))->assertCreated()->json('data');
}

test('price determination applies the customer list, quantity breaks, validity and promotions by priority', function (): void {
    $customerList = PriceList::create(['tenant_id' => $this->tenant->id, 'name' => 'Key accounts', 'currency' => 'EUR', 'is_active' => true]);
    $customerList->items()->createMany([
        ['product_id' => $this->product->id, 'price' => 90, 'min_quantity' => 1],
        ['product_id' => $this->product->id, 'price' => 80, 'min_quantity' => 10],
        ['product_id' => $this->product->id, 'price' => 50, 'min_quantity' => 1, 'valid_from' => '2026-06-01'],
    ]);
    $this->contact->update(['price_list_id' => $customerList->id]);

    $policy = salesCycleApi()->postJson('/api/v1/sales/discount-policies', [
        'code' => 'SPRING10', 'name' => 'Spring promotion', 'discount_type' => 'percentage', 'value' => 10,
        'valid_from' => '2026-03-01', 'valid_to' => '2026-05-31', 'priority' => 10, 'auto_apply' => true,
    ])->assertCreated();
    expect($policy->json('data.auto_apply'))->toBeTrue();

    salesCycleApi()->postJson('/api/v1/sales/discount-policies', [
        'code' => 'BULK', 'name' => 'Bulk widgets', 'discount_type' => 'fixed_amount', 'value' => 0, 'priority' => 20, 'auto_apply' => true,
        'rules' => [['product_id' => $this->product->id, 'min_threshold' => 10, 'discount_fixed_amount' => 2]],
    ])->assertCreated();

    salesCycleApi()->postJson('/api/v1/sales/discount-policies', [
        'code' => 'LATE', 'name' => 'Exclusive late promo', 'discount_type' => 'percentage', 'value' => 50, 'priority' => 30, 'auto_apply' => true, 'is_stackable' => false,
    ])->assertCreated();

    $single = salesCycleApi()->getJson('/api/v1/sales/pricing/price?'.http_build_query(['product_id' => $this->product->id, 'contact_id' => $this->contact->id, 'quantity' => 1]))->assertOk();
    expect($single->json('data.list_price'))->toBe('90.0000')
        ->and($single->json('data.price_list_id'))->toBe($customerList->id)
        ->and($single->json('data.discount_percentage'))->toBe('10.00')
        ->and($single->json('data.net_unit_price'))->toBe('81.0000')
        ->and(collect($single->json('data.applied_discounts'))->pluck('code')->all())->toBe(['SPRING10']);

    $bulk = salesCycleApi()->getJson('/api/v1/sales/pricing/price?'.http_build_query(['product_id' => $this->product->id, 'contact_id' => $this->contact->id, 'quantity' => 12]))->assertOk();
    expect($bulk->json('data.list_price'))->toBe('80.0000')
        ->and($bulk->json('data.unit_price'))->toBe('78.0000')
        ->and($bulk->json('data.fixed_discount_per_unit'))->toBe('2.0000')
        ->and($bulk->json('data.net_unit_price'))->toBe('70.2000')
        ->and(collect($bulk->json('data.applied_discounts'))->pluck('code')->all())->toBe(['SPRING10', 'BULK']);

    $summer = salesCycleApi()->getJson('/api/v1/sales/pricing/price?'.http_build_query(['product_id' => $this->product->id, 'contact_id' => $this->contact->id, 'quantity' => 1, 'date' => '2026-06-15']))->assertOk();
    expect($summer->json('data.list_price'))->toBe('50.0000')
        ->and(collect($summer->json('data.applied_discounts'))->pluck('code')->all())->toBe(['LATE'])
        ->and($summer->json('data.net_unit_price'))->toBe('25.0000');
});

test('discount policies are kept by sales managers, validated and isolated per company', function (): void {
    salesCycleApi()->postJson('/api/v1/sales/discount-policies', ['code' => 'X', 'name' => 'Too much', 'discount_type' => 'percentage', 'value' => 120])
        ->assertUnprocessable()->assertJsonValidationErrors('value');
    salesCycleApi()->postJson('/api/v1/sales/discount-policies', ['code' => 'Y', 'name' => 'No target', 'discount_type' => 'percentage', 'value' => 5, 'rules' => [['discount_percent' => 5]]])
        ->assertUnprocessable()->assertJsonValidationErrors('rules.0');

    $id = salesCycleApi()->postJson('/api/v1/sales/discount-policies', ['code' => 'OK', 'name' => 'Fine', 'discount_type' => 'percentage', 'value' => 5])->assertCreated()->json('data.id');
    salesCycleApi()->putJson("/api/v1/sales/discount-policies/{$id}", ['priority' => 5])->assertOk()->assertJsonPath('data.priority', 5);

    salesCycleActAs('sales');
    salesCycleApi()->getJson('/api/v1/sales/discount-policies')->assertOk()->assertJsonCount(1, 'data');
    salesCycleApi()->putJson("/api/v1/sales/discount-policies/{$id}", ['priority' => 1])->assertForbidden();

    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@sales.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($stranger);
    $this->withHeader('X-Tenant-Id', $other->id)->getJson("/api/v1/sales/discount-policies/{$id}")->assertNotFound();
    $this->withHeader('X-Tenant-Id', $other->id)->getJson('/api/v1/sales/pricing/price?product_id='.$this->product->id)->assertUnprocessable();
});

test('confirming an order over the credit limit puts it on hold until a credit manager releases it', function (): void {
    salesCycleApi()->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => $this->contact->id, 'credit_limit' => 1000])->assertOk();

    $draft = salesCycleOrder(15, ['status' => 'draft']);
    expect($draft['status'])->toBe('draft');

    $held = salesCycleApi()->postJson("/api/v1/sales/orders/{$draft['id']}/confirm")->assertOk();
    expect($held->json('data.status'))->toBe('on_hold')
        ->and($held->json('data.credit_status'))->toBe('on_hold')
        ->and($held->json('data.credit_hold_reason'))->toContain('credit limit');

    // The hold is not bypassed by confirming again, nor by delivering.
    salesCycleApi()->postJson("/api/v1/sales/orders/{$draft['id']}/confirm")->assertUnprocessable();
    salesCycleApi()->postJson("/api/v1/sales/orders/{$draft['id']}/delivery-notes")->assertUnprocessable();

    $holdId = DB::table('sales_order_holds')->where('sales_order_id', $draft['id'])->value('id');

    salesCycleActAs('sales');
    salesCycleApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$holdId}/release", ['notes' => 'self release'])->assertForbidden();
    salesCycleApi()->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => $this->contact->id, 'credit_limit' => 999999])->assertForbidden();

    Sanctum::actingAs($this->admin);
    salesCycleApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$holdId}/release", ['notes' => 'Approved by CFO'])->assertOk();
    salesCycleApi()->postJson("/api/v1/invoicing/customer-credit/holds/{$holdId}/release")->assertUnprocessable();

    expect(salesCycleApi()->getJson("/api/v1/sales/orders/{$draft['id']}")->json('data.status'))->toBe('confirmed');

    $profile = salesCycleApi()->getJson("/api/v1/invoicing/customer-credit/{$this->contact->id}")->assertOk();
    expect((float) $profile->json('pending_orders_amount'))->toBe(1830.0);
});

test('the credit check mode of the company decides between block, warn and off', function (): void {
    salesCycleApi()->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => $this->contact->id, 'credit_limit' => 100])->assertOk();
    app(TenantContext::class)->setTenant($this->tenant);

    app(TenantPreferences::class)->update('sales', ['credit_check_mode' => 'warn']);
    $warned = salesCycleOrder(1);
    expect($warned['status'])->toBe('confirmed')->and($warned['credit_status'])->toBe('warning');

    app(TenantPreferences::class)->update('sales', ['credit_check_mode' => 'off']);
    $unchecked = salesCycleOrder(1);
    expect($unchecked['status'])->toBe('confirmed')->and($unchecked['credit_status'])->toBe('not_checked');

    salesCycleApi()->putJson('/api/v1/settings/preferences/sales', ['credit_check_mode' => 'sometimes'])->assertUnprocessable();
});

test('availability to promise, reservations, partial deliveries with backorders and cancellation go through the inventory module', function (): void {
    $order = salesCycleOrder(15);
    $line = $order['items'][0]['id'];

    $atp = salesCycleApi()->getJson("/api/v1/sales/orders/{$order['id']}/availability")->assertOk();
    expect($atp->json('data.fully_available'))->toBeFalse()
        ->and($atp->json('data.lines.0.quantity_available'))->toBe('10.0000')
        ->and($atp->json('data.lines.0.shortage'))->toBe('5.0000');

    $reserved = salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/reserve")->assertOk();
    expect($reserved->json('data.inventory_status'))->toBe('partially_reserved')
        ->and((float) $reserved->json('data.items.0.quantity_reserved'))->toBe(10.0)
        ->and((float) salesCycleStock()['reserved'])->toBe(10.0);

    $note = salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/delivery-notes", [
        'lines' => [$line => 6],
        'transport_reason' => 'Vendita', 'goods_appearance' => 'Cartoni', 'packages_count' => 2, 'gross_weight_kg' => 12.5,
        'carrier_name' => 'BRT S.p.A.', 'shipping_terms' => 'Franco', 'transport_by' => 'carrier',
    ])->assertCreated();
    expect($note->json('data.status'))->toBe('issued')
        ->and($note->json('data.shipping_terms'))->toBe('Franco')
        ->and($note->json('data.transport_by'))->toBe('carrier')
        ->and($note->json('data.packages_count'))->toBe(2);

    $stock = salesCycleStock();
    expect((float) $stock['on_hand'])->toBe(4.0)
        ->and((float) $stock['reserved'])->toBe(4.0)
        ->and((float) $stock['shortfall'])->toBe(0.0);

    $backorders = salesCycleApi()->getJson('/api/v1/sales/backorders?contact_id='.$this->contact->id)->assertOk();
    expect($backorders->json('data'))->toHaveCount(1)
        ->and($backorders->json('data.0.quantity_open'))->toBe('9.0000')
        ->and($backorders->json('data.0.open_net_value'))->toBe('900.00');

    salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/delivery-notes", ['lines' => [$line => 10]])->assertUnprocessable();
    salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/delivery-notes", ['transport_by' => 'pigeon'])->assertUnprocessable()->assertJsonValidationErrors('transport_by');
    salesCycleApi()->putJson("/api/v1/sales/orders/{$order['id']}", ['items' => [['description' => 'Other', 'quantity' => 1, 'unit_price' => 1, 'tax_rate' => 22]]])->assertUnprocessable()->assertJsonValidationErrors('items');
    salesCycleApi()->deleteJson('/api/v1/sales/delivery-notes/'.$note->json('data.id'))->assertUnprocessable();
    salesCycleApi()->deleteJson("/api/v1/sales/orders/{$order['id']}")->assertUnprocessable();

    salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/cancel", ['reason' => 'Customer withdrew the rest'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect((float) salesCycleStock()['reserved'])->toBe(0.0);
});

test('a quote can be revised, expires past its validity and is no longer converted then', function (): void {
    $payload = ['contact_id' => $this->contact->id, 'date' => '2026-03-01', 'valid_until' => '2026-03-31', 'currency' => 'EUR', 'status' => 'sent',
        'items' => [['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 22]]];
    $quote = salesCycleApi()->postJson('/api/v1/sales/quotes', $payload)->assertCreated()->json('data');

    $revision = salesCycleApi()->postJson("/api/v1/sales/quotes/{$quote['id']}/revise", ['items' => [['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 3, 'unit_price' => 95, 'tax_rate' => 22]]])->assertCreated();
    expect($revision->json('data.revision_number'))->toBe(2)
        ->and($revision->json('data.revision_of_quote_id'))->toBe($quote['id'])
        ->and($revision->json('data.quote_number'))->not->toBe($quote['quote_number'])
        ->and($revision->json('data.total_net'))->toBe('285.00');
    expect(salesCycleApi()->getJson("/api/v1/sales/quotes/{$quote['id']}")->json('data.status'))->toBe('superseded');
    salesCycleApi()->postJson("/api/v1/sales/quotes/{$quote['id']}/convert-to-order")->assertUnprocessable();
    salesCycleApi()->postJson("/api/v1/sales/quotes/{$quote['id']}/revise")->assertUnprocessable();

    $expiring = salesCycleApi()->postJson('/api/v1/sales/quotes', array_merge($payload, ['valid_until' => '2026-03-10']))->assertCreated()->json('data.id');
    salesCycleApi()->postJson("/api/v1/sales/quotes/{$expiring}/convert-to-order")->assertUnprocessable()->assertJsonValidationErrors('quote');
    salesCycleApi()->postJson("/api/v1/sales/quotes/{$expiring}/delivery-note")->assertUnprocessable();

    salesCycleApi()->postJson('/api/v1/sales/quotes/expire')->assertOk()->assertJsonPath('data.expired', 1);
    expect(salesCycleApi()->getJson("/api/v1/sales/quotes/{$expiring}")->json('data.status'))->toBe('expired');

    $this->artisan('sales:expire-quotes')->assertSuccessful();

    $order = salesCycleApi()->postJson('/api/v1/sales/quotes/'.$revision->json('data.id').'/convert-to-order')->assertCreated();
    expect($order->json('data.status'))->toBe('confirmed')->and($order->json('data.total_net'))->toBe('285.00');
});

test('a quote shipped straight away moves the stock through the one delivery note writer', function (): void {
    $quote = salesCycleApi()->postJson('/api/v1/sales/quotes', ['contact_id' => $this->contact->id, 'date' => '2026-03-15', 'currency' => 'EUR',
        'items' => [['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 3, 'unit_price' => 100, 'tax_rate' => 22]]])->assertCreated()->json('data.id');

    $note = salesCycleApi()->postJson("/api/v1/sales/quotes/{$quote}/delivery-note", ['carrier_name' => 'Own van', 'transport_by' => 'sender'])->assertCreated();
    expect($note->json('data.carrier_name'))->toBe('Own van')
        ->and((float) salesCycleStock()['on_hand'])->toBe(7.0);
});

test('a return of delivered and invoiced goods goes back into stock and is credited on the invoice', function (): void {
    $order = salesCycleOrder(4);
    $note = salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/delivery-notes")->assertCreated()->json('data');
    $invoice = salesCycleApi()->postJson("/api/v1/sales/delivery-notes/{$note['id']}/invoice")->assertCreated()->json('data');
    expect((float) salesCycleStock()['on_hand'])->toBe(6.0);

    salesCycleApi()->postJson('/api/v1/sales/returns', ['delivery_note_id' => $note['id'], 'reason' => 'Too many', 'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 5]]])
        ->assertUnprocessable()->assertJsonValidationErrors('items');

    $return = salesCycleApi()->postJson('/api/v1/sales/returns', [
        'delivery_note_id' => $note['id'], 'reason' => 'Wrong colour',
        'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 3]],
    ])->assertCreated();
    expect($return->json('data.return_number'))->toBe('RMA-2026-0001')
        ->and($return->json('data.status'))->toBe('requested')
        ->and($return->json('data.items.0.unit_price'))->toBe('100.0000');
    $returnId = $return->json('data.id');
    $returnLine = $return->json('data.items.0.id');

    salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/receive")->assertUnprocessable();

    salesCycleActAs('sales');
    salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/approve")->assertForbidden();
    salesCycleActAs('accountant_readonly');
    salesCycleApi()->postJson('/api/v1/sales/returns', ['delivery_note_id' => $note['id'], 'reason' => 'x', 'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 1]]])->assertForbidden();
    Sanctum::actingAs($this->admin);

    salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/receive", ['items' => [['id' => $returnLine, 'quantity_received' => 4]]])->assertUnprocessable();

    $received = salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/receive", ['items' => [['id' => $returnLine, 'quantity_received' => 2, 'condition' => 'resellable']]])->assertOk();
    expect($received->json('data.status'))->toBe('received')
        ->and((float) salesCycleStock()['on_hand'])->toBe(8.0);

    $credited = salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/credit-note")->assertOk();
    expect($credited->json('data.status'))->toBe('credited');

    $creditNote = DB::table('invoices')->where('id', $credited->json('data.credit_note_id'))->first();
    expect($creditNote->invoice_type)->toBe('credit_note')
        ->and($creditNote->related_invoice_id)->toBe($invoice['id'])
        ->and((string) $creditNote->total_net)->toBe('200.00')
        ->and((string) $creditNote->total_gross)->toBe('244.00');

    salesCycleApi()->postJson("/api/v1/sales/returns/{$returnId}/credit-note")->assertOk()->assertJsonPath('data.credit_note_id', $creditNote->id);
    expect(DB::table('invoices')->where('related_invoice_id', $invoice['id'])->where('invoice_type', 'credit_note')->count())->toBe(1);

    // A second return can take back only what is left (4 delivered, 3 already returned).
    salesCycleApi()->postJson('/api/v1/sales/returns', ['delivery_note_id' => $note['id'], 'reason' => 'More', 'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 2]]])
        ->assertUnprocessable();

    $kpis = salesCycleApi()->getJson('/api/v1/sales/kpis?from=2026-03-01&to=2026-03-31')->assertOk();
    expect($kpis->json('data.returns.credited'))->toBe(1)
        ->and($kpis->json('data.fill_rate.line_fill_rate'))->toBe('100.00');
});

test('returns that were never invoiced cannot be credited, and rejected returns free their quantities', function (): void {
    $order = salesCycleOrder(2);
    $note = salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/delivery-notes")->assertCreated()->json('data');
    $body = ['delivery_note_id' => $note['id'], 'reason' => 'Broken', 'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 2, 'condition' => 'damaged']]];

    $rejected = salesCycleApi()->postJson('/api/v1/sales/returns', $body)->assertCreated()->json('data.id');
    salesCycleApi()->postJson("/api/v1/sales/returns/{$rejected}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
    salesCycleApi()->postJson("/api/v1/sales/returns/{$rejected}/reject", ['reason' => 'Out of warranty'])->assertOk()->assertJsonPath('data.status', 'rejected');

    $return = salesCycleApi()->postJson('/api/v1/sales/returns', $body)->assertCreated()->json('data.id');
    salesCycleApi()->postJson("/api/v1/sales/returns/{$return}/approve")->assertOk();
    salesCycleApi()->postJson("/api/v1/sales/returns/{$return}/receive")->assertOk();

    // Damaged goods are not restocked.
    expect((float) salesCycleStock()['on_hand'])->toBe(8.0);

    salesCycleApi()->postJson("/api/v1/sales/returns/{$return}/credit-note")->assertUnprocessable()->assertJsonValidationErrors('invoice_id');
    salesCycleApi()->postJson("/api/v1/sales/returns/{$return}/cancel")->assertUnprocessable();
});

test('sales documents of another company are not found, and its references are refused', function (): void {
    $order = salesCycleOrder(1);
    $note = salesCycleApi()->postJson("/api/v1/sales/orders/{$order['id']}/delivery-notes")->assertCreated()->json('data');
    $return = salesCycleApi()->postJson('/api/v1/sales/returns', ['delivery_note_id' => $note['id'], 'reason' => 'x', 'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 1]]])->assertCreated()->json('data.id');

    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger2@sales.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($stranger);
    $foreign = fn () => $this->withHeader('X-Tenant-Id', $other->id);

    $foreign()->postJson("/api/v1/sales/orders/{$order['id']}/confirm")->assertNotFound();
    $foreign()->getJson("/api/v1/sales/orders/{$order['id']}/availability")->assertNotFound();
    $foreign()->postJson("/api/v1/sales/orders/{$order['id']}/reserve")->assertNotFound();
    $foreign()->postJson("/api/v1/sales/orders/{$order['id']}/cancel", ['reason' => 'x'])->assertNotFound();
    $foreign()->getJson("/api/v1/sales/returns/{$return}")->assertNotFound();
    $foreign()->postJson("/api/v1/sales/returns/{$return}/approve")->assertNotFound();
    $foreign()->getJson("/api/v1/invoicing/customer-credit/{$this->contact->id}")->assertNotFound();
    $foreign()->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => $this->contact->id, 'credit_limit' => 10])->assertUnprocessable();
    $foreign()->postJson('/api/v1/sales/returns', ['delivery_note_id' => $note['id'], 'reason' => 'x', 'items' => [['delivery_note_item_id' => $note['items'][0]['id'], 'quantity' => 1]]])
        ->assertUnprocessable()->assertJsonValidationErrors('delivery_note_id');
    expect($foreign()->getJson('/api/v1/sales/backorders')->assertOk()->json('data'))->toBe([]);
});

test('the sales KPIs report backlog, fill rate and quote conversion', function (): void {
    salesCycleOrder(5);
    $quotes = ['contact_id' => $this->contact->id, 'date' => '2026-03-02', 'currency' => 'EUR', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 22]]];
    salesCycleApi()->postJson('/api/v1/sales/quotes', array_merge($quotes, ['status' => 'rejected']))->assertCreated();
    $accepted = salesCycleApi()->postJson('/api/v1/sales/quotes', $quotes)->assertCreated()->json('data.id');
    salesCycleApi()->postJson("/api/v1/sales/quotes/{$accepted}/convert-to-order")->assertCreated();

    $kpis = salesCycleApi()->getJson('/api/v1/sales/kpis?from=2026-03-01&to=2026-03-31')->assertOk();
    expect($kpis->json('data.order_backlog.open_orders'))->toBe(2)
        ->and($kpis->json('data.order_backlog.open_net_value'))->toBe('600.00')
        ->and($kpis->json('data.quote_conversion.quotes'))->toBe(2)
        ->and($kpis->json('data.quote_conversion.conversion_rate'))->toBe('50.00')
        ->and($kpis->json('data.quote_conversion.converted_to_order'))->toBe(1)
        ->and($kpis->json('data.fill_rate.line_fill_rate'))->toBe('0.00');

    salesCycleApi()->getJson('/api/v1/sales/kpis?from=2026-03-31&to=2026-03-01')->assertUnprocessable();
});

test('the invoicing module order and delivery endpoints share the credit check, the approval policy and the stock movements', function (): void {
    $order = salesCycleApi()->postJson('/api/v1/invoicing/orders', [
        'contact_id' => $this->contact->id,
        'items' => [['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 3, 'tax_rate' => 22]],
    ])->assertCreated()->json();
    expect($order['status'])->toBe('confirmed')
        ->and((float) $order['items'][0]['unit_price'])->toBe(100.0);

    salesCycleActAs('sales');
    salesCycleApi()->postJson("/api/v1/invoicing/orders/{$order['id']}/approve")->assertForbidden();
    salesCycleActAs('sales_manager');
    salesCycleApi()->postJson("/api/v1/invoicing/orders/{$order['id']}/approve")->assertOk()->assertJsonPath('status', 'approved');
    Sanctum::actingAs($this->admin);

    $note = salesCycleApi()->postJson('/api/v1/invoicing/delivery-notes', [
        'sales_order_id' => $order['id'],
        'shipping_terms' => 'Assegnato',
        'items' => [['sales_order_item_id' => $order['items'][0]['id'], 'quantity' => 2]],
    ])->assertCreated()->json();
    expect((float) salesCycleStock()['on_hand'])->toBe(10.0);

    salesCycleApi()->postJson("/api/v1/invoicing/delivery-notes/{$note['id']}/confirm")->assertUnprocessable();
    salesCycleApi()->postJson("/api/v1/invoicing/delivery-notes/{$note['id']}/dispatch")->assertOk();
    expect((float) salesCycleStock()['on_hand'])->toBe(8.0);
    salesCycleApi()->postJson("/api/v1/invoicing/delivery-notes/{$note['id']}/dispatch")->assertUnprocessable();
    salesCycleApi()->postJson("/api/v1/invoicing/delivery-notes/{$note['id']}/confirm")->assertOk();

    salesCycleApi()->postJson('/api/v1/invoicing/delivery-notes', [
        'sales_order_id' => $order['id'],
        'items' => [['sales_order_item_id' => $order['items'][0]['id'], 'quantity' => 2]],
    ])->assertUnprocessable();

    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger3@sales.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($stranger);
    $this->withHeader('X-Tenant-Id', $other->id)->postJson('/api/v1/invoicing/delivery-notes', [
        'sales_order_id' => $order['id'],
        'items' => [['sales_order_item_id' => $order['items'][0]['id'], 'quantity' => 1]],
    ])->assertUnprocessable()->assertJsonValidationErrors('sales_order_id');
    $this->withHeader('X-Tenant-Id', $other->id)->postJson("/api/v1/invoicing/orders/{$order['id']}/cancel")->assertNotFound();
});
