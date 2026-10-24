<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\DashboardLayout;
use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const DW_WIDGET_IDS = ['revenue_kpi', 'low_stock_alert', 'ecommerce_sync_status', 'cash_flow_trend', 'top_selling_products', 'overdue_invoices'];

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->colleague = User::create(['name' => 'Anna', 'email' => 'anna@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);

    foreach ([$this->user, $this->colleague] as $member) {
        $member->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    }
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $this->customer = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Mario Rossi', 'email' => 'mario@example.com', 'type' => 'customer', 'country' => 'IT']);
    $this->foreignCustomer = Contact::create(['tenant_id' => $this->otherTenant->id, 'company_name' => 'Foreign Buyer', 'email' => 'buyer@example.com', 'type' => 'customer', 'country' => 'IT']);
});

function dwApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dwInvoice(string $number, string $date, float $net, array $overrides = []): Invoice
{
    $tenantId = $overrides['tenant_id'] ?? test()->tenant->id;

    return Invoice::create(array_merge([
        'tenant_id' => $tenantId, 'contact_id' => $tenantId === test()->tenant->id ? test()->customer->id : test()->foreignCustomer->id,
        'invoice_number' => $number, 'date' => $date, 'due_date' => $date, 'total_net' => $net, 'total_tax' => round($net * 0.22, 2),
        'total_gross' => round($net * 1.22, 2), 'payment_status' => 'unpaid', 'invoice_type' => 'standard',
    ], $overrides));
}

function dwItem(Invoice $invoice, Product $product, float $quantity, float $total): void
{
    InvoiceItem::create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'description' => $product->name, 'quantity' => $quantity, 'unit_price' => $total / $quantity, 'line_total' => $total]);
}

function dwProduct(string $sku, array $overrides = []): Product
{
    return Product::create(array_merge(['tenant_id' => test()->tenant->id, 'sku' => $sku, 'name' => "Product {$sku}", 'sale_price' => 10, 'tax_rate' => 22, 'track_inventory' => true], $overrides));
}

function dwStock(Product $product, float $quantity): void
{
    dwApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $product->id, 'quantity' => $quantity])->assertCreated();
}

function dwWidget(array $response, string $id): array
{
    return collect($response['data']['widgets'])->firstWhere('id', $id);
}

// ---------------------------------------------------------------------------------------------------------------
// Registry and layouts
// ---------------------------------------------------------------------------------------------------------------

test('the registry lists every widget with metadata, default dimensions and options', function (): void {
    $widgets = dwApi()->getJson('/api/v1/dashboard/available-widgets')->assertOk()->json('data');

    expect(array_column($widgets, 'id'))->toEqualCanonicalizing(DW_WIDGET_IDS);

    $byId = collect($widgets)->keyBy('id');
    expect($byId['revenue_kpi']['type'])->toBe('kpi_card')
        ->and($byId['cash_flow_trend']['type'])->toBe('line_chart')
        ->and($byId['top_selling_products']['type'])->toBe('bar_chart')
        ->and($byId['low_stock_alert']['type'])->toBe('data_table')
        ->and($byId['revenue_kpi']['title'])->toBe('Revenue')
        ->and($byId['revenue_kpi']['default_dimensions'])->toBe(['w' => 4, 'h' => 3])
        ->and($byId['revenue_kpi']['configurable_options']['period']['values'])->toContain('current_month', 'last_30_days')
        ->and($byId['top_selling_products']['configurable_options']['limit']['default'])->toBe(5)
        ->and($byId['ecommerce_sync_status']['configurable_options'])->toBe([]);
});

test('a user without a saved layout gets the factory layout generated on the fly', function (): void {
    $layout = dwApi()->getJson('/api/v1/dashboard/layout')->assertOk()->json('data');

    expect($layout['is_customized'])->toBeFalse()
        ->and($layout['id'])->toBeNull()
        ->and($layout['name'])->toBe('Default Dashboard')
        ->and(array_column($layout['widgets_layout'], 'widget_id'))->toEqualCanonicalizing(DW_WIDGET_IDS)
        ->and(DashboardLayout::withoutGlobalScopes()->count())->toBe(0);

    $revenue = collect($layout['widgets_layout'])->firstWhere('widget_id', 'revenue_kpi');
    expect($revenue)->toBe(['widget_id' => 'revenue_kpi', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'settings' => []]);

    foreach ($layout['widgets_layout'] as $entry) {
        expect($entry['x'] + $entry['w'])->toBeLessThanOrEqual(12);
    }
});

test('a custom layout is saved, replaced in place and read back', function (): void {
    $saved = dwApi()->putJson('/api/v1/dashboard/layout', [
        'name' => 'My home',
        'widgets_layout' => [
            ['widget_id' => 'top_selling_products', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4, 'settings' => ['limit' => 3, 'source' => 'orders']],
            ['widget_id' => 'revenue_kpi', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 2, 'settings' => ['period' => 'current_year']],
        ],
    ])->assertOk();

    expect($saved->json('data.is_customized'))->toBeTrue()->and($saved->json('data.name'))->toBe('My home');

    $read = dwApi()->getJson('/api/v1/dashboard/layout')->assertOk()->json('data');
    expect($read['widgets_layout'])->toHaveCount(2)
        ->and($read['widgets_layout'][0]['widget_id'])->toBe('top_selling_products')
        ->and($read['widgets_layout'][0]['settings'])->toBe(['limit' => 3, 'source' => 'orders'])
        ->and($read['widgets_layout'][1]['w'])->toBe(6);

    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => [['widget_id' => 'low_stock_alert', 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 5]]])->assertOk()->assertJsonPath('data.name', 'My home');

    expect(DashboardLayout::count())->toBe(1)
        ->and(dwApi()->getJson('/api/v1/dashboard/layout')->json('data.widgets_layout.*.widget_id'))->toBe(['low_stock_alert']);

    // An empty dashboard is a valid choice.
    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => []])->assertOk();
    expect(dwApi()->getJson('/api/v1/dashboard/layout')->json('data.widgets_layout'))->toBe([]);
});

test('the factory reset removes the customisation and restores the default layout', function (): void {
    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => [['widget_id' => 'revenue_kpi', 'x' => 3, 'y' => 2, 'w' => 5, 'h' => 2]]])->assertOk();
    expect(DashboardLayout::count())->toBe(1);

    $reset = dwApi()->postJson('/api/v1/dashboard/layout/reset')->assertOk();

    expect($reset->json('data.is_customized'))->toBeFalse()
        ->and($reset->json('data.widgets_layout'))->toHaveCount(6)
        ->and(DashboardLayout::count())->toBe(0)
        ->and(dwApi()->getJson('/api/v1/dashboard/layout')->json('data.is_customized'))->toBeFalse();

    // Resetting an untouched dashboard is harmless.
    dwApi()->postJson('/api/v1/dashboard/layout/reset')->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------
// Widget data
// ---------------------------------------------------------------------------------------------------------------

test('revenue compares the period with the previous one, netting credit notes and ignoring proformas', function (): void {
    dwInvoice('INV-1', '2026-03-02', 1000);
    dwInvoice('INV-2', '2026-03-10', 200);
    dwInvoice('CN-1', '2026-03-11', 100, ['invoice_type' => 'credit_note']);
    dwInvoice('PRO-1', '2026-03-12', 500, ['invoice_type' => 'proforma']);
    dwInvoice('INV-OLD', '2026-02-20', 800);
    dwInvoice('INV-FOREIGN', '2026-03-05', 9999, ['tenant_id' => $this->otherTenant->id]);

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=revenue_kpi')->json(), 'revenue_kpi')['data'];

    expect($data['value'])->toEqual(1100)
        ->and($data['invoices_count'])->toBe(3)
        ->and($data['previous_value'])->toEqual(800)
        ->and($data['change_percent'])->toEqual(37.5)
        ->and($data['currency'])->toBe('EUR')
        ->and($data['from'])->toBe('2026-03-01')
        ->and($data['to'])->toBe('2026-03-31')
        ->and($data['previous_from'])->toBe('2026-02-01');
});

test('the revenue period is configurable through the saved layout settings', function (): void {
    dwInvoice('INV-1', '2026-03-02', 1000);
    dwInvoice('INV-2', '2026-02-20', 800);
    dwInvoice('INV-3', '2026-01-20', 400);

    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => [['widget_id' => 'revenue_kpi', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3, 'settings' => ['period' => 'previous_month']]]])->assertOk();

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data')->json(), 'revenue_kpi');
    expect($data['settings'])->toBe(['period' => 'previous_month'])
        ->and($data['data']['value'])->toEqual(800)
        ->and($data['data']['previous_value'])->toEqual(400);

    // Without history the percentage is not defined.
    $year = dwApi()->getJson('/api/v1/dashboard/data?widgets=revenue_kpi')->json();
    expect(dwWidget($year, 'revenue_kpi')['data']['value'])->toEqual(800);
});

test('overdue invoices list the open balance and the average delay', function (): void {
    $partial = dwInvoice('INV-A', '2026-01-13', 100, ['due_date' => '2026-02-13', 'total_gross' => 122, 'payment_status' => 'partially_paid']);
    Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $partial->id, 'amount' => 22, 'paid_at' => '2026-02-20']);
    dwInvoice('INV-B', '2026-02-20', 50, ['due_date' => '2026-03-05', 'total_gross' => 50]);
    dwInvoice('INV-PAID', '2026-01-01', 70, ['due_date' => '2026-02-01', 'payment_status' => 'paid']);
    dwInvoice('INV-FUTURE', '2026-03-10', 70, ['due_date' => '2026-04-10']);
    dwInvoice('CN-1', '2026-01-01', 70, ['due_date' => '2026-02-01', 'invoice_type' => 'credit_note']);
    dwInvoice('INV-FOREIGN', '2026-01-01', 500, ['due_date' => '2026-02-01', 'tenant_id' => $this->otherTenant->id]);

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=overdue_invoices')->json(), 'overdue_invoices')['data'];

    expect($data['count'])->toBe(2)
        ->and($data['total_outstanding'])->toEqual(150)
        ->and($data['average_days_overdue'])->toEqual(20)
        ->and($data['invoices'][0]['invoice_number'])->toBe('INV-A')
        ->and($data['invoices'][0]['days_overdue'])->toBe(30)
        ->and($data['invoices'][0]['outstanding'])->toEqual(100)
        ->and($data['invoices'][0]['contact_name'])->toBe('Mario Rossi')
        ->and($data['invoices'][1]['days_overdue'])->toBe(10);
});

test('low stock lists tracked active products and variants at or below their minimum', function (): void {
    $low = dwProduct('LOW', ['min_stock' => 5]);
    $enough = dwProduct('OK', ['min_stock' => 5]);
    $variant = dwProduct('LOW-M', ['min_stock' => 1, 'is_variant' => true, 'parent_id' => $low->id]);
    $unmonitored = dwProduct('FREE');
    $inactive = dwProduct('OFF', ['min_stock' => 5, 'is_active' => false]);
    $service = dwProduct('SRV', ['min_stock' => 5, 'track_inventory' => false]);
    dwProduct('FOREIGN', ['tenant_id' => $this->otherTenant->id, 'min_stock' => 5]);

    dwStock($low, 3);
    dwStock($enough, 10);
    dwStock($unmonitored, 0.5);
    dwStock($inactive, 1);

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=low_stock_alert')->json(), 'low_stock_alert')['data'];

    expect($data['count'])->toBe(2)
        ->and(array_column($data['products'], 'sku'))->toBe(['LOW', 'LOW-M'])
        ->and($data['products'][0]['stock'])->toEqual(3)
        ->and($data['products'][0]['min_stock'])->toEqual(5)
        ->and($data['products'][1]['stock'])->toEqual(0)
        ->and($data['products'][1]['is_variant'])->toBeTrue();

    dwStock($low, 2);
    $after = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=low_stock_alert')->json(), 'low_stock_alert')['data'];
    expect(array_column($after['products'], 'sku'))->toBe(['LOW-M', 'LOW']);

    dwStock($low, 1);
    expect(dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=low_stock_alert')->json(), 'low_stock_alert')['data']['count'])->toBe(1);
});

test('the cash flow trend covers the latest months with money in and out', function (): void {
    $invoice = dwInvoice('INV-1', '2026-01-10', 1000);
    Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'amount' => 300, 'paid_at' => '2026-03-10']);
    Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'paid_at' => '2026-02-02']);
    Payment::create(['tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'amount' => 50, 'paid_at' => '2025-08-02']);

    $supplier = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Supplier', 'email' => 'supplier@example.com', 'type' => 'supplier', 'country' => 'IT']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'P-1', 'date' => '2026-03-01', 'due_date' => '2026-03-20', 'total_amount' => 80, 'payment_status' => 'paid']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'P-2', 'date' => '2026-03-01', 'due_date' => '2026-03-25', 'total_amount' => 999, 'payment_status' => 'unpaid']);
    PassiveInvoice::create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'invoice_number' => 'P-3', 'date' => '2026-01-05', 'total_amount' => 20, 'payment_status' => 'paid']);

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=cash_flow_trend')->json(), 'cash_flow_trend')['data'];

    expect(array_column($data['months'], 'month'))->toBe(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'])
        ->and($data['months'][5])->toMatchArray(['inflow' => 300, 'outflow' => 80, 'net' => 220])
        ->and($data['months'][4]['inflow'])->toEqual(100)
        ->and($data['months'][3]['outflow'])->toEqual(20)
        ->and($data['total_inflow'])->toEqual(400)
        ->and($data['total_outflow'])->toEqual(100);

    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => [['widget_id' => 'cash_flow_trend', 'x' => 0, 'y' => 0, 'w' => 8, 'h' => 4, 'settings' => ['months' => 2]]]])->assertOk();
    expect(dwWidget(dwApi()->getJson('/api/v1/dashboard/data')->json(), 'cash_flow_trend')['data']['months'])->toHaveCount(2);
});

test('top selling products ranks the current month by quantity from invoices or orders', function (): void {
    $a = dwProduct('A');
    $b = dwProduct('B');
    $c = dwProduct('C');

    $march = dwInvoice('INV-1', '2026-03-03', 100);
    dwItem($march, $a, 5, 50);
    dwItem($march, $b, 3, 90);
    $credit = dwInvoice('CN-1', '2026-03-04', 10, ['invoice_type' => 'credit_note']);
    dwItem($credit, $a, 1, 10);
    dwItem(dwInvoice('INV-OLD', '2026-02-03', 10), $c, 50, 50);
    dwItem(dwInvoice('PRO-1', '2026-03-05', 10, ['invoice_type' => 'proforma']), $c, 50, 50);

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=top_selling_products')->json(), 'top_selling_products')['data'];

    expect(array_column($data['products'], 'sku'))->toBe(['A', 'B'])
        ->and($data['products'][0]['quantity'])->toEqual(4)
        ->and($data['products'][0]['revenue'])->toEqual(40)
        ->and($data['products'][0]['name'])->toBe('Product A');

    $order = DB::table('sales_orders');
    $orderId = (string) Str::uuid();
    $order->insert(['id' => $orderId, 'tenant_id' => $this->tenant->id, 'contact_id' => $this->customer->id, 'order_number' => 'SO-1', 'date' => '2026-03-08', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_order_items')->insert(['id' => (string) Str::uuid(), 'sales_order_id' => $orderId, 'product_id' => $c->id, 'description' => 'C', 'quantity' => 9, 'line_total' => 90, 'created_at' => now(), 'updated_at' => now()]);

    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => [['widget_id' => 'top_selling_products', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 4, 'settings' => ['source' => 'orders', 'limit' => 1]]]])->assertOk();
    $orders = dwWidget(dwApi()->getJson('/api/v1/dashboard/data')->json(), 'top_selling_products')['data'];

    expect($orders['source'])->toBe('orders')->and(array_column($orders['products'], 'sku'))->toBe(['C']);
});

test('the e-commerce widget reports orders, failures and the last synchronisation per channel', function (): void {
    $channel = EcommerceChannel::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Shop', 'driver' => 'woocommerce', 'api_url' => 'https://woo.example.com',
        'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'], 'webhook_secret' => 'secret-secret-secret',
        'last_sync_at' => '2026-03-15 09:45:00', 'last_sync_summary' => ['imported' => 4, 'updated' => 1, 'duplicates' => 2, 'failed' => 3, 'at' => '2026-03-15T09:45:00+00:00'],
    ]);
    foreach (['101', '102'] as $remote) {
        EcommerceMapping::create(['tenant_id' => $this->tenant->id, 'channel_id' => $channel->id, 'entity_type' => 'order', 'local_id' => (string) Str::uuid(), 'remote_id' => $remote]);
    }
    EcommerceMapping::create(['tenant_id' => $this->tenant->id, 'channel_id' => $channel->id, 'entity_type' => 'product', 'local_id' => (string) Str::uuid(), 'remote_id' => '9']);
    EcommerceChannel::create([
        'tenant_id' => $this->otherTenant->id, 'name' => 'Foreign shop', 'driver' => 'shopify', 'credentials' => ['access_token' => 'x'], 'webhook_secret' => 'secret-secret-secret',
    ]);

    $data = dwWidget(dwApi()->getJson('/api/v1/dashboard/data?widgets=ecommerce_sync_status')->json(), 'ecommerce_sync_status')['data'];

    expect($data['channels'])->toHaveCount(1)
        ->and($data['channels'][0]['name'])->toBe('Shop')
        ->and($data['channels'][0]['orders_imported'])->toBe(2)
        ->and($data['channels'][0]['last_run']['failed'])->toBe(3)
        ->and($data['channels'][0]['last_run']['imported'])->toBe(4)
        ->and($data['channels'][0]['last_sync_at'])->not->toBeNull()
        ->and($data['total_orders_imported'])->toBe(2)
        ->and($data['total_failed_last_run'])->toBe(3);
});

test('data is resolved for the whole layout or only for the widgets requested', function (): void {
    expect(array_column(dwApi()->getJson('/api/v1/dashboard/data')->assertOk()->json('data.widgets'), 'id'))->toEqualCanonicalizing(DW_WIDGET_IDS);

    $some = dwApi()->getJson('/api/v1/dashboard/data?widgets=revenue_kpi,low_stock_alert,revenue_kpi')->assertOk();
    expect(array_column($some->json('data.widgets'), 'id'))->toBe(['revenue_kpi', 'low_stock_alert'])
        ->and($some->json('data.widgets.0.type'))->toBe('kpi_card')
        ->and($some->json('data.generated_at'))->not->toBeNull();

    dwApi()->getJson('/api/v1/dashboard/data?widgets=revenue_kpi,nope')->assertUnprocessable();

    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => [['widget_id' => 'low_stock_alert', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]])->assertOk();
    expect(array_column(dwApi()->getJson('/api/v1/dashboard/data')->json('data.widgets'), 'id'))->toBe(['low_stock_alert']);
});

// ---------------------------------------------------------------------------------------------------------------
// Isolation
// ---------------------------------------------------------------------------------------------------------------

test('layouts are private to the user and to the tenant', function (): void {
    dwApi()->putJson('/api/v1/dashboard/layout', ['name' => 'Leo', 'widgets_layout' => [['widget_id' => 'revenue_kpi', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]])->assertOk();

    // The same user in another tenant starts from the factory layout and saves a separate one.
    expect(dwApi($this->otherTenant->id)->getJson('/api/v1/dashboard/layout')->json('data.is_customized'))->toBeFalse();
    dwApi($this->otherTenant->id)->putJson('/api/v1/dashboard/layout', ['name' => 'Leo elsewhere', 'widgets_layout' => []])->assertOk();

    expect(dwApi()->getJson('/api/v1/dashboard/layout')->json('data.name'))->toBe('Leo');

    // A colleague of the same tenant neither sees nor overwrites it.
    Sanctum::actingAs($this->colleague);
    expect(dwApi()->getJson('/api/v1/dashboard/layout')->json('data.is_customized'))->toBeFalse();

    dwApi()->putJson('/api/v1/dashboard/layout', ['name' => 'Anna', 'widgets_layout' => [['widget_id' => 'low_stock_alert', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]])->assertOk();
    dwApi()->postJson('/api/v1/dashboard/layout/reset')->assertOk();

    Sanctum::actingAs($this->user);
    expect(dwApi()->getJson('/api/v1/dashboard/layout')->json('data.name'))->toBe('Leo')
        ->and(dwApi($this->otherTenant->id)->getJson('/api/v1/dashboard/layout')->json('data.name'))->toBe('Leo elsewhere')
        ->and(DashboardLayout::withoutGlobalScopes()->count())->toBe(2);

    // Resetting in one tenant leaves the other tenant alone.
    dwApi()->postJson('/api/v1/dashboard/layout/reset')->assertOk();
    expect(dwApi($this->otherTenant->id)->getJson('/api/v1/dashboard/layout')->json('data.name'))->toBe('Leo elsewhere');
});

test('widget data never mixes tenants', function (): void {
    dwInvoice('INV-FOREIGN', '2026-03-05', 5000, ['tenant_id' => $this->otherTenant->id, 'due_date' => '2026-01-01']);

    $revenue = dwApi()->getJson('/api/v1/dashboard/data?widgets=revenue_kpi,overdue_invoices')->json();
    expect(dwWidget($revenue, 'revenue_kpi')['data']['value'])->toEqual(0)
        ->and(dwWidget($revenue, 'overdue_invoices')['data']['count'])->toBe(0);

    $other = dwApi($this->otherTenant->id)->getJson('/api/v1/dashboard/data?widgets=revenue_kpi')->json();
    expect(dwWidget($other, 'revenue_kpi')['data']['value'])->toEqual(5000);
});

// ---------------------------------------------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------------------------------------------

test('layout validation rejects unknown widgets and invalid coordinates, sizes and settings', function (): void {
    $put = fn (array $widgets) => dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => $widgets]);
    $valid = ['widget_id' => 'revenue_kpi', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3];

    $put([['widget_id' => 'nope'] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.widget_id']);
    $put([['x' => -1] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.x']);
    $put([['y' => -2] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.y']);
    $put([['w' => 0] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.w']);
    $put([['h' => 0] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.h']);
    $put([['x' => 'left'] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.x']);
    $put([['w' => 2.5] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.w']);
    $put([['x' => 9, 'w' => 4] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.w']);
    $put([['w' => 13] + $valid])->assertUnprocessable();
    $put([$valid, $valid])->assertUnprocessable();
    $put([['settings' => ['period' => 'next_decade']] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.settings.period']);
    $put([['settings' => ['colour' => 'red']] + $valid])->assertUnprocessable()->assertJsonValidationErrors(['widgets_layout.0.settings.colour']);
    $put([['widget_id' => 'top_selling_products', 'settings' => ['limit' => 99]] + $valid])->assertUnprocessable();
    $put([['widget_id' => 'top_selling_products', 'settings' => ['limit' => '3']] + $valid])->assertUnprocessable();
    $put(['not-an-object'])->assertUnprocessable();
    dwApi()->putJson('/api/v1/dashboard/layout', [])->assertUnprocessable();
    dwApi()->putJson('/api/v1/dashboard/layout', ['widgets_layout' => 'revenue_kpi'])->assertUnprocessable();

    expect(DashboardLayout::count())->toBe(0);
});
