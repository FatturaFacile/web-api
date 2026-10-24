<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

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

    $user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $this->product = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'WID-1', 'name' => 'Widget', 'cost_price' => 5, 'sale_price' => 10,
        'tax_rate' => 22, 'track_inventory' => true,
    ]);
    $this->service = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'SRV-1', 'name' => 'Consulting hour', 'cost_price' => 0, 'sale_price' => 50,
        'tax_rate' => 22, 'track_inventory' => false,
    ]);

    $this->terminal = posApi()->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-01', 'name' => 'Front desk'])->assertCreated()->json('data');
    posApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->product->id, 'quantity' => 10])->assertCreated();
});

function posApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

function openSession(float $float = 100.0): string
{
    return posApi()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => test()->terminal['id'], 'opening_float' => $float])->assertCreated()->json('data.id');
}

function stockOf(string $productId): float
{
    return (float) collect(posApi()->getJson('/api/v1/inventory/stock-levels')->json('data'))->firstWhere('product_id', $productId)['quantity'];
}

function saleBody(string $sessionId, array $overrides = []): array
{
    return array_merge([
        'pos_session_id' => $sessionId,
        'payment_method' => 'cash',
        'items' => [['product_id' => test()->product->id, 'quantity' => 3]],
    ], $overrides);
}

test('terminals are unique per tenant and a terminal has one open session at a time', function (): void {
    posApi()->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-01', 'name' => 'Duplicate'])
        ->assertUnprocessable()->assertJsonValidationErrors('terminal_code');

    $session = openSession();
    posApi()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => $this->terminal['id']])->assertUnprocessable();

    posApi()->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 100])->assertOk();
    posApi()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => $this->terminal['id']])->assertCreated();
});

test('a cash sale uses product defaults, computes totals server-side, returns change and unloads stock', function (): void {
    $session = openSession();

    $sale = posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['cash_tendered' => 50]))->assertCreated();

    expect($sale->json('data.receipt_number'))->toBe('REC-2026-0001')
        ->and((float) $sale->json('data.total_net'))->toEqual(30.0)
        ->and((float) $sale->json('data.total_gross'))->toEqual(36.6)
        ->and((float) $sale->json('data.cash_amount'))->toEqual(36.6)
        ->and($sale->json('data.change_due'))->toEqual(13.4)
        ->and($sale->json('data.items.0.description'))->toBe('Widget')
        ->and(stockOf($this->product->id))->toEqual(7.0)
        ->and((float) PosSession::find($session)->total_sales)->toEqual(36.6);

    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['cash_tendered' => 10]))->assertUnprocessable();
});

test('services do not move stock and client-sent totals are ignored', function (): void {
    $session = openSession();

    $sale = posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, [
        'items' => [['product_id' => $this->service->id, 'quantity' => 1]],
        'total_gross' => 1, // ignored
    ]))->assertCreated();

    expect((float) $sale->json('data.total_gross'))->toEqual(61.0)
        ->and(stockOf($this->product->id))->toEqual(10.0);
});

test('mixed payments split cash and card and reject impossible splits', function (): void {
    $session = openSession();

    $sale = posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'mixed', 'cash_amount' => 20]))->assertCreated();
    expect((float) $sale->json('data.cash_amount'))->toEqual(20.0)->and((float) $sale->json('data.card_amount'))->toEqual(16.6);

    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'mixed', 'cash_amount' => 40]))->assertUnprocessable();
    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'mixed']))->assertUnprocessable();
});

test('prepaid sales debit the card atomically and fail without side effects when funds are short', function (): void {
    $session = openSession();
    $card = posApi()->postJson('/api/v1/loyalty-cards', ['type' => 'prepaid', 'initial_balance' => 50])->json('data.id');

    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'prepaid', 'loyalty_card_id' => $card]))->assertCreated();
    expect((float) posApi()->getJson("/api/v1/loyalty-cards/{$card}")->json('data.balance'))->toEqual(13.4);

    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'prepaid', 'loyalty_card_id' => $card]))->assertUnprocessable();
    expect(stockOf($this->product->id))->toEqual(7.0)
        ->and((float) PosSession::find($session)->total_sales)->toEqual(36.6);

    $loyaltyCard = posApi()->postJson('/api/v1/loyalty-cards', ['type' => 'loyalty'])->json('data.id');
    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'prepaid', 'loyalty_card_id' => $loyaltyCard]))->assertUnprocessable();
});

test('loyalty cards earn one point per currency unit and refunds reverse stock, takings and points', function (): void {
    $session = openSession();
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT']);
    $card = posApi()->postJson('/api/v1/loyalty-cards', ['type' => 'loyalty', 'contact_id' => $contact->id])->json('data.id');

    $sale = posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['loyalty_card_id' => $card]))->assertCreated();
    expect((float) posApi()->getJson("/api/v1/loyalty-cards/{$card}")->json('data.balance'))->toEqual(36.0)
        ->and($sale->json('data.contact_id'))->toBe($contact->id);

    posApi()->postJson('/api/v1/retail/pos/sales/'.$sale->json('data.id').'/refund')->assertOk()->assertJsonPath('data.status', 'refunded');

    expect((float) posApi()->getJson("/api/v1/loyalty-cards/{$card}")->json('data.balance'))->toEqual(0.0)
        ->and(stockOf($this->product->id))->toEqual(10.0)
        ->and((float) PosSession::find($session)->total_sales)->toEqual(0.0);

    posApi()->postJson('/api/v1/retail/pos/sales/'.$sale->json('data.id').'/refund')->assertUnprocessable();
});

test('fiscal transmission happens once and blocks refunds', function (): void {
    $session = openSession();
    $sale = posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session))->json('data.id');

    $receipt = posApi()->postJson("/api/v1/retail/pos/sales/{$sale}/rt-transmit")->assertCreated();
    expect($receipt->json('data.rt_status'))->toBe('transmitted')->and($receipt->json('data.fiscal_document_number'))->toBe('DOC-20260001');

    posApi()->postJson("/api/v1/retail/pos/sales/{$sale}/rt-transmit")->assertUnprocessable();
    posApi()->postJson("/api/v1/retail/pos/sales/{$sale}/refund")->assertUnprocessable();
});

test('closing a session computes the expected cash and difference, and the report summarises takings', function (): void {
    $session = openSession(100);
    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session))->assertCreated(); // 36.60 cash
    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session, ['payment_method' => 'card']))->assertCreated(); // 36.60 card
    $refunded = posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session))->json('data.id');
    posApi()->postJson("/api/v1/retail/pos/sales/{$refunded}/refund")->assertOk();

    $report = posApi()->getJson("/api/v1/retail/pos/sessions/{$session}/report")->assertOk()->json('data');
    expect($report['sales_count'])->toBe(2)->and($report['refunds_count'])->toBe(1)
        ->and($report['total_gross'])->toEqual(73.2)
        ->and($report['cash_takings'])->toEqual(36.6)
        ->and($report['expected_cash'])->toEqual(136.6);

    $closed = posApi()->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 135.6])->assertOk();
    expect((float) $closed->json('data.expected_cash'))->toEqual(136.6)->and((float) $closed->json('data.cash_difference'))->toEqual(-1.0);

    posApi()->postJson('/api/v1/retail/pos/sales', saleBody($session))->assertUnprocessable();
    posApi()->postJson("/api/v1/retail/pos/sessions/{$session}/close", ['closing_cash' => 1])->assertUnprocessable();
});

test('delivery notes unload tracked stock automatically, services excluded', function (): void {
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Milan Customer SpA', 'country' => 'IT']);
    $order = posApi()->postJson('/api/v1/sales/orders', [
        'contact_id' => $contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'status' => 'confirmed',
        'items' => [
            ['product_id' => $this->product->id, 'description' => 'Widget', 'quantity' => 4, 'unit_price' => 10, 'tax_rate' => 22],
            ['product_id' => $this->service->id, 'description' => 'Consulting', 'quantity' => 2, 'unit_price' => 50, 'tax_rate' => 22],
        ],
    ])->assertCreated()->json('data.id');

    posApi()->postJson("/api/v1/sales/orders/{$order}/delivery-notes")->assertCreated();

    expect(stockOf($this->product->id))->toEqual(6.0)
        ->and(collect(posApi()->getJson('/api/v1/inventory/stock-levels')->json('data'))->pluck('product_id')->contains($this->service->id))->toBeFalse();
});

test('manual stock adjustments are signed, limited to tracked products and filterable by warehouse', function (): void {
    posApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->product->id, 'quantity' => -4])->assertCreated();
    expect(stockOf($this->product->id))->toEqual(6.0);

    posApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->service->id, 'quantity' => 1])->assertUnprocessable();
    posApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->product->id, 'quantity' => 0])->assertUnprocessable();

    $other = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'AUX', 'name' => 'Auxiliary']);
    expect(posApi()->getJson("/api/v1/inventory/stock-levels?warehouse_id={$other->id}")->json('data'))->toBeEmpty();
});
