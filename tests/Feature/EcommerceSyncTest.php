<?php

declare(strict_types=1);

use App\Exceptions\EcommerceException;
use App\Jobs\PushStockToEcommerceJob;
use App\Models\Contact;
use App\Models\EcommerceChannel;
use App\Models\EcommerceMapping;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Ecommerce\Drivers\ShopifyDriver;
use App\Services\Ecommerce\Drivers\WooCommerceDriver;
use App\Services\Ecommerce\EcommerceManager;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

const EC_SECRET = 'webhook-secret-0123456789';

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);

    $this->mug = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'MUG-1', 'name' => 'Mug', 'sale_price' => 10, 'tax_rate' => 22, 'track_inventory' => true]);
    $this->parent = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'TSH', 'name' => 'T-shirt <Pro> & Co', 'sale_price' => 20, 'tax_rate' => 22, 'track_inventory' => false]);
    $this->small = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'TSH-M', 'name' => 'T-shirt M', 'sale_price' => 20, 'tax_rate' => 22, 'track_inventory' => true,
        'parent_id' => $this->parent->id, 'is_variant' => true, 'attributes' => ['Size' => 'M', 'Color' => 'Red'],
    ]);
    $this->large = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'TSH-L', 'name' => 'T-shirt L', 'sale_price' => 22, 'tax_rate' => 22, 'track_inventory' => true,
        'parent_id' => $this->parent->id, 'is_variant' => true, 'attributes' => ['Size' => 'L', 'Color' => 'Red'],
    ]);

    // Stock is set before any channel exists, so no synchronisation is triggered.
    foreach ([$this->mug, $this->small, $this->large] as $product) {
        ecApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $product->id, 'quantity' => 10])->assertCreated();
    }
});

function ecApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ecChannel(string $driver, array $overrides = [], ?string $tenantId = null): EcommerceChannel
{
    $defaults = match ($driver) {
        'woocommerce' => ['api_url' => 'https://woo.example.com', 'credentials' => ['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_test'], 'settings' => []],
        'shopify' => ['api_url' => null, 'credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'shpat_test'], 'settings' => ['location_id' => '9001']],
        default => ['api_url' => 'https://presta.example.com', 'credentials' => ['api_key' => 'PSKEY'], 'settings' => []],
    };

    return EcommerceChannel::create(array_merge([
        'tenant_id' => $tenantId ?? test()->tenant->id,
        'name' => ucfirst($driver).' shop',
        'driver' => $driver,
        'webhook_secret' => EC_SECRET,
        'is_active' => true,
        'sync_stock_enabled' => false,
        'sync_orders_enabled' => true,
    ], $defaults, $overrides));
}

function ecMap(EcommerceChannel $channel, Product $product, string $type, string $remoteId, array $extra = []): EcommerceMapping
{
    return EcommerceMapping::create([
        'tenant_id' => $channel->tenant_id, 'channel_id' => $channel->id, 'entity_type' => $type,
        'local_id' => $product->id, 'remote_id' => $remoteId, 'remote_sku' => $product->sku, 'extra_data' => $extra,
    ]);
}

function ecStock(Product $product): float
{
    return app(TenantContext::class)->runWithTenant(test()->tenant, fn (): float => app(StockService::class)->quantityOf($product->id));
}

/**
 * @param  array<int, array<string, mixed>>  $items
 * @return array<string, mixed>
 */
function ecWooOrder(int $id, array $items, string $status = 'processing', string $email = 'buyer@example.com'): array
{
    return [
        'id' => $id, 'number' => (string) $id, 'status' => $status, 'currency' => 'EUR', 'date_created_gmt' => '2026-10-20T10:00:00',
        'billing' => ['first_name' => 'Mario', 'last_name' => 'Rossi', 'email' => $email, 'address_1' => 'Via Roma 1', 'postcode' => '20100', 'city' => 'Milano', 'state' => 'MI', 'country' => 'IT'],
        'line_items' => $items,
    ];
}

/**
 * @return array<string, mixed>
 */
function ecWooItem(string $sku, int $productId, int $variationId, int $quantity = 2): array
{
    return ['product_id' => $productId, 'variation_id' => $variationId, 'sku' => $sku, 'name' => $sku, 'quantity' => $quantity, 'total' => (string) (10 * $quantity), 'total_tax' => (string) (2.2 * $quantity)];
}

function ecSignedPost(EcommerceChannel $channel, string $body, array $headers, ?string $secret = null)
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $server['CONTENT_TYPE'] = 'application/json';

    return test()->call('POST', "/api/v1/webhooks/ecommerce/{$channel->id}", [], [], [], $server, $body);
}

// ---------------------------------------------------------------------------------------------------------------
// Product hierarchy
// ---------------------------------------------------------------------------------------------------------------

test('products keep their variant hierarchy and attributes', function (): void {
    $fresh = Product::find($this->small->id);

    expect($fresh->attributes)->toBe(['Size' => 'M', 'Color' => 'Red'])
        ->and($fresh->is_variant)->toBeTrue()
        ->and($fresh->parent->id)->toBe($this->parent->id)
        ->and($this->parent->variants()->pluck('sku')->sort()->values()->all())->toBe(['TSH-L', 'TSH-M'])
        ->and(Product::variantsOnly()->count())->toBe(2)
        ->and(Product::withoutVariants()->pluck('sku')->sort()->values()->all())->toBe(['MUG-1', 'TSH']);
});

// ---------------------------------------------------------------------------------------------------------------
// Channel management
// ---------------------------------------------------------------------------------------------------------------

test('a channel is created with a generated webhook secret shown once and credentials never exposed', function (): void {
    $response = ecApi()->postJson('/api/v1/ecommerce/channels', [
        'name' => 'My Woo', 'driver' => 'woocommerce', 'api_url' => 'https://shop.example.com',
        'credentials' => ['consumer_key' => 'ck_secret_value', 'consumer_secret' => 'cs_secret_value'],
    ])->assertCreated();

    $id = $response->json('data.id');

    expect($response->json('data.webhook_secret'))->toHaveLength(40)
        ->and($response->json('data.credential_keys'))->toBe(['consumer_key', 'consumer_secret'])
        ->and($response->json('data.webhook_url'))->toEndWith("/api/v1/webhooks/ecommerce/{$id}")
        ->and($response->getContent())->not->toContain('ck_secret_value');

    $show = ecApi()->getJson("/api/v1/ecommerce/channels/{$id}")->assertOk();
    expect($show->json('data'))->not->toHaveKey('webhook_secret')
        ->and($show->getContent())->not->toContain('cs_secret_value');

    $raw = DB::table('ecommerce_channels')->where('id', $id)->first();
    expect($raw->credentials)->not->toContain('ck_secret_value')
        ->and($raw->webhook_secret)->not->toBe($response->json('data.webhook_secret'));
});

test('channel validation rejects unknown drivers, missing credentials and private urls', function (): void {
    ecApi()->postJson('/api/v1/ecommerce/channels', ['name' => 'X', 'driver' => 'magento', 'credentials' => ['a' => 'b']])->assertUnprocessable();
    ecApi()->postJson('/api/v1/ecommerce/channels', ['name' => 'X', 'driver' => 'woocommerce', 'api_url' => 'https://x.example.com', 'credentials' => ['consumer_key' => 'ck']])->assertUnprocessable();
    ecApi()->postJson('/api/v1/ecommerce/channels', ['name' => 'X', 'driver' => 'woocommerce', 'api_url' => 'http://127.0.0.1', 'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs']])->assertUnprocessable();
    ecApi()->postJson('/api/v1/ecommerce/channels', ['name' => 'X', 'driver' => 'prestashop', 'api_url' => 'https://x.example.com', 'credentials' => []])->assertUnprocessable();
});

test('channel names are unique per tenant and update merges stored credentials', function (): void {
    $channel = ecChannel('woocommerce');

    ecApi()->postJson('/api/v1/ecommerce/channels', [
        'name' => $channel->name, 'driver' => 'woocommerce', 'api_url' => 'https://x.example.com',
        'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'],
    ])->assertUnprocessable();

    ecApi()->putJson("/api/v1/ecommerce/channels/{$channel->id}", ['credentials' => ['consumer_secret' => 'cs_new'], 'sync_stock_enabled' => true])->assertOk();

    $fresh = EcommerceChannel::withoutGlobalScopes()->find($channel->id);
    expect($fresh->credentials)->toBe(['consumer_key' => 'ck_test', 'consumer_secret' => 'cs_new'])
        ->and($fresh->sync_stock_enabled)->toBeTrue();
});

test('channels are isolated between tenants and deleting one removes its mappings', function (): void {
    $foreign = ecChannel('woocommerce', ['name' => 'Foreign'], $this->otherTenant->id);
    $own = ecChannel('woocommerce');
    ecMap($own, $this->mug, 'product', '55');

    ecApi()->getJson("/api/v1/ecommerce/channels/{$foreign->id}")->assertNotFound();
    ecApi()->deleteJson("/api/v1/ecommerce/channels/{$foreign->id}")->assertNotFound();
    expect(ecApi()->getJson('/api/v1/ecommerce/channels')->json('data'))->toHaveCount(1);

    ecApi()->deleteJson("/api/v1/ecommerce/channels/{$own->id}")->assertOk();
    expect(EcommerceMapping::withoutGlobalScopes()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------
// Connection tests
// ---------------------------------------------------------------------------------------------------------------

test('test-connection reports success and failure for every driver', function (string $driver, string $pattern): void {
    $channel = ecChannel($driver);

    Http::fake([$pattern => Http::sequence()->push(['ok' => true], 200)->push(['error' => 'no'], 401)]);
    ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/test-connection")->assertOk()->assertJsonPath('data.connected', true)->assertJsonPath('data.driver', $driver);

    ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/test-connection")->assertOk()->assertJsonPath('data.connected', false);
})->with([
    'woocommerce' => ['woocommerce', 'woo.example.com/*'],
    'shopify' => ['shopify', 'demo.myshopify.com/*'],
    'prestashop' => ['prestashop', 'presta.example.com/*'],
]);

test('test-connection sends the platform authentication', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    ecApi()->postJson('/api/v1/ecommerce/channels/'.ecChannel('woocommerce')->id.'/test-connection')->assertOk();
    ecApi()->postJson('/api/v1/ecommerce/channels/'.ecChannel('shopify')->id.'/test-connection')->assertOk();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'woo.example.com/wp-json/wc/v3') && $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_test:cs_test')));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'demo.myshopify.com/admin/api/') && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test'));
});

test('test-connection is false when the shop is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    ecApi()->postJson('/api/v1/ecommerce/channels/'.ecChannel('woocommerce')->id.'/test-connection')->assertOk()->assertJsonPath('data.connected', false);
});

// ---------------------------------------------------------------------------------------------------------------
// Orders
// ---------------------------------------------------------------------------------------------------------------

test('woocommerce orders are imported with variant mapping, stock deduction and contact reuse, then skipped on replay', function (): void {
    $channel = ecChannel('woocommerce');
    ecMap($channel, $this->parent, 'product', '700');
    ecMap($channel, $this->small, 'product_variant', '701', ['parent_remote_id' => '700']);
    ecMap($channel, $this->mug, 'product', '800');

    Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Mario Rossi', 'email' => 'Buyer@Example.com', 'type' => 'customer', 'country' => 'IT']);

    Http::fake(['woo.example.com/wp-json/wc/v3/orders*' => Http::response([
        ecWooOrder(1001, [ecWooItem('TSH-M', 700, 701, 3), ecWooItem('MUG-1', 800, 0, 1)]),
        // No mapping for these: resolved by exact SKU.
        ecWooOrder(1002, [ecWooItem('TSH-L', 999, 998, 2)], 'completed'),
    ])]);

    $result = ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['orders']]);

    $result->assertOk();
    expect($result->json('data.orders.imported'))->toBe(2)
        ->and(SalesOrder::count())->toBe(2)
        ->and(Contact::where('email', 'like', '%@example.com')->count())->toBe(1)
        ->and(ecStock($this->small))->toBe(7.0)
        ->and(ecStock($this->mug))->toBe(9.0)
        ->and(ecStock($this->large))->toBe(8.0)
        ->and(EcommerceChannel::withoutGlobalScopes()->find($channel->id)->last_sync_at)->not->toBeNull();

    $again = ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['orders']]);
    expect($again->json('data.orders.imported'))->toBe(0)
        ->and($again->json('data.orders.duplicates'))->toBe(2)
        ->and(SalesOrder::count())->toBe(2)
        ->and(ecStock($this->small))->toBe(7.0);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'after='));
});

test('a variant line without own mapping falls back to the parent product mapping', function (): void {
    $channel = ecChannel('woocommerce');
    ecMap($channel, $this->parent, 'product', '700');

    Http::fake(['woo.example.com/*' => Http::response([ecWooOrder(2001, [ecWooItem('', 700, 0, 1)])])]);

    ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['orders']])->assertOk()->assertJsonPath('data.orders.imported', 1);

    expect(SalesOrder::count())->toBe(1);
});

test('shopify orders are imported with tax-inclusive prices converted and cancelled orders leave stock untouched', function (): void {
    $channel = ecChannel('shopify');
    ecMap($channel, $this->mug, 'product', '300', ['inventory_item_id' => '4000']);

    $order = fn (int $id, ?string $cancelled): array => [
        'id' => $id, 'name' => "#{$id}", 'currency' => 'EUR', 'created_at' => '2026-10-20T10:00:00+02:00', 'taxes_included' => true,
        'financial_status' => 'paid', 'cancelled_at' => $cancelled, 'email' => 'shopper@example.com',
        'billing_address' => ['first_name' => 'Anna', 'last_name' => 'Verdi', 'zip' => '00100', 'city' => 'Roma', 'country_code' => 'IT'],
        'line_items' => [['product_id' => 300, 'variant_id' => 301, 'sku' => 'MUG-1', 'name' => 'Mug', 'quantity' => 2, 'price' => '12.20', 'tax_lines' => [['rate' => 0.22]]]],
    ];

    Http::fake(['demo.myshopify.com/admin/api/*/orders.json*' => Http::response(['orders' => [$order(5001, null), $order(5002, '2026-10-21T10:00:00+02:00')]])]);

    ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['orders']])->assertOk()->assertJsonPath('data.orders.imported', 2);

    $imported = SalesOrder::with('items')->get();
    expect($imported)->toHaveCount(2)
        ->and((float) $imported->first()->items->first()->unit_price)->toBe(10.0)
        ->and(ecStock($this->mug))->toBe(8.0);
});

test('prestashop orders are imported resolving customer, address, country and currency', function (): void {
    $channel = ecChannel('prestashop');
    ecMap($channel, $this->mug, 'product', '12');

    Http::fake([
        'presta.example.com/api/orders*' => Http::response(['orders' => [[
            'id' => 7, 'reference' => 'ABCDEF', 'id_customer' => 3, 'id_address_invoice' => 4, 'id_currency' => 1, 'current_state' => 2,
            'date_add' => '2026-10-20 10:00:00',
            'associations' => ['order_rows' => [['product_id' => 12, 'product_attribute_id' => 0, 'product_reference' => 'MUG-1', 'product_name' => 'Mug', 'product_quantity' => 1, 'unit_price_tax_excl' => '10.000000', 'unit_price_tax_incl' => '12.200000']]],
        ]]]),
        'presta.example.com/api/customers/3*' => Http::response(['customer' => ['id' => 3, 'email' => 'presta@example.com', 'firstname' => 'Luca', 'lastname' => 'Bianchi']]),
        'presta.example.com/api/addresses/4*' => Http::response(['address' => ['id' => 4, 'address1' => 'Via Po 2', 'postcode' => '10100', 'city' => 'Torino', 'id_country' => 10, 'phone' => '']]),
        'presta.example.com/api/countries/10*' => Http::response(['country' => ['id' => 10, 'iso_code' => 'IT']]),
        'presta.example.com/api/currencies/1*' => Http::response(['currency' => ['id' => 1, 'iso_code' => 'EUR']]),
    ]);

    $result = ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['orders']])->assertOk();

    expect($result->json('data.orders.imported'))->toBe(1)
        ->and(Contact::where('email', 'presta@example.com')->exists())->toBeTrue()
        ->and(ecStock($this->mug))->toBe(9.0);
});

test('the order status map of a channel overrides the default status', function (): void {
    $channel = ecChannel('woocommerce', ['settings' => ['order_status_map' => ['processing' => 'in_production']]]);

    Http::fake(['woo.example.com/*' => Http::response([ecWooOrder(3001, [ecWooItem('MUG-1', 1, 0, 1)])])]);

    ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['orders']])->assertOk();

    expect(SalesOrder::first()->status)->toBe('in_production');
});

test('sync endpoint respects switches, inactive channels, filters and shop errors', function (): void {
    $disabled = ecChannel('woocommerce', ['sync_orders_enabled' => false]);
    ecApi()->postJson("/api/v1/ecommerce/channels/{$disabled->id}/sync", ['filters' => ['orders', 'stock']])
        ->assertOk()->assertJsonPath('data.orders', 'disabled')->assertJsonPath('data.stock', 'disabled');

    $inactive = ecChannel('shopify', ['is_active' => false]);
    ecApi()->postJson("/api/v1/ecommerce/channels/{$inactive->id}/sync")->assertUnprocessable();

    ecApi()->postJson("/api/v1/ecommerce/channels/{$disabled->id}/sync", ['filters' => ['nonsense']])->assertUnprocessable();

    $broken = ecChannel('prestashop');
    Http::fake(['presta.example.com/*' => Http::response('boom', 500)]);
    ecApi()->postJson("/api/v1/ecommerce/channels/{$broken->id}/sync", ['filters' => ['orders']])->assertStatus(502);
    expect(EcommerceChannel::withoutGlobalScopes()->find($broken->id)->last_sync_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------
// Stock push
// ---------------------------------------------------------------------------------------------------------------

test('stock is pushed to every driver using its own mapping', function (): void {
    $woo = ecChannel('woocommerce', ['sync_stock_enabled' => true]);
    ecMap($woo, $this->parent, 'product', '700');
    ecMap($woo, $this->small, 'product_variant', '701', ['parent_remote_id' => '700']);

    $shopify = ecChannel('shopify', ['sync_stock_enabled' => true]);
    ecMap($shopify, $this->mug, 'product', '300', ['inventory_item_id' => '4000']);

    $presta = ecChannel('prestashop', ['sync_stock_enabled' => true]);
    ecMap($presta, $this->mug, 'product', '12');

    Http::fake([
        'woo.example.com/*' => Http::response(['id' => 701]),
        'demo.myshopify.com/*' => Http::response(['inventory_level' => []]),
        'presta.example.com/api/stock_availables*' => Http::sequence()
            ->push(['stock_availables' => [['id' => 99]]])
            ->push('<prestashop/>', 200),
    ]);

    PushStockToEcommerceJob::dispatchSync($this->tenant->id, $this->small->id);
    PushStockToEcommerceJob::dispatchSync($this->tenant->id, $this->mug->id);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && str_contains($request->url(), 'products/700/variations/701') && $request['stock_quantity'] === 10);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'inventory_levels/set.json') && $request['inventory_item_id'] === 4000 && $request['location_id'] === 9001 && $request['available'] === 10);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && str_contains($request->url(), 'stock_availables/99'));

    expect(EcommerceMapping::withoutGlobalScopes()->where('channel_id', $presta->id)->first()->extra_data['stock_available_id'])->toBe('99');
});

test('unmapped products, disabled channels and other tenants never trigger requests', function (): void {
    ecChannel('woocommerce', ['sync_stock_enabled' => true]);
    ecChannel('woocommerce', ['name' => 'Off', 'sync_stock_enabled' => false]);

    $foreign = ecChannel('woocommerce', ['name' => 'Foreign', 'sync_stock_enabled' => true], $this->otherTenant->id);
    ecMap($foreign, $this->mug, 'product', '1');

    PushStockToEcommerceJob::dispatchSync($this->tenant->id, $this->mug->id);

    Http::assertNothingSent();
});

test('a failing shop is logged and does not break the stock job', function (): void {
    $channel = ecChannel('woocommerce', ['sync_stock_enabled' => true]);
    ecMap($channel, $this->mug, 'product', '800');

    Http::fake(['woo.example.com/*' => Http::response('boom', 500)]);

    PushStockToEcommerceJob::dispatchSync($this->tenant->id, $this->mug->id);

    Http::assertSentCount(1);
});

test('stock movements queue a stock push only when a channel syncs stock', function (): void {
    Queue::fake();

    ecApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->mug->id, 'quantity' => 1])->assertCreated();
    Queue::assertNothingPushed();

    ecChannel('woocommerce', ['sync_stock_enabled' => true]);
    ecApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $this->mug->id, 'quantity' => 1])->assertCreated();

    Queue::assertPushed(PushStockToEcommerceJob::class);
});

// ---------------------------------------------------------------------------------------------------------------
// Product push
// ---------------------------------------------------------------------------------------------------------------

test('woocommerce products are published as variable products with variations and mapped', function (): void {
    $channel = ecChannel('woocommerce');

    Http::fake([
        'woo.example.com/wp-json/wc/v3/products/500/variations' => Http::sequence()->push(['id' => 501])->push(['id' => 502]),
        'woo.example.com/wp-json/wc/v3/products' => Http::sequence()->push(['id' => 400])->push(['id' => 500]),
    ]);

    $result = ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['products']])->assertOk();

    expect($result->json('data.products'))->not->toBeEmpty();

    $mappings = EcommerceMapping::withoutGlobalScopes()->where('channel_id', $channel->id)->get();
    expect($mappings->where('entity_type', 'product_variant'))->toHaveCount(2)
        ->and($mappings->where('entity_type', 'product'))->toHaveCount(2);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/products/500/variations') && isset($request['attributes']));

    // A second run publishes nothing: everything is mapped.
    Http::fake();
    ecApi()->postJson("/api/v1/ecommerce/channels/{$channel->id}/sync", ['filters' => ['products']])->assertOk();
    Http::assertNothingSent();
});

// ---------------------------------------------------------------------------------------------------------------
// Webhooks
// ---------------------------------------------------------------------------------------------------------------

test('woocommerce webhooks are verified, imported once and can cancel an order restoring stock', function (): void {
    $channel = ecChannel('woocommerce');
    ecMap($channel, $this->mug, 'product', '800');

    $body = json_encode(ecWooOrder(4001, [ecWooItem('MUG-1', 800, 0, 2)]));
    $headers = ['X-WC-Webhook-Topic' => 'order.created', 'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, EC_SECRET, true))];

    ecSignedPost($channel, $body, $headers)->assertOk()->assertJsonPath('data.imported', 1);
    ecSignedPost($channel, $body, $headers)->assertOk()->assertJsonPath('data.duplicates', 1);
    expect(SalesOrder::count())->toBe(1)->and(ecStock($this->mug))->toBe(8.0);

    $cancelled = json_encode(ecWooOrder(4001, [ecWooItem('MUG-1', 800, 0, 2)], 'cancelled'));
    ecSignedPost($channel, $cancelled, ['X-WC-Webhook-Topic' => 'order.updated', 'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $cancelled, EC_SECRET, true))])
        ->assertOk()->assertJsonPath('data.updated', 1);

    expect(SalesOrder::first()->status)->toBe('cancelled')->and(ecStock($this->mug))->toBe(10.0);

    $reactivated = json_encode(ecWooOrder(4001, [ecWooItem('MUG-1', 800, 0, 2)], 'processing'));
    ecSignedPost($channel, $reactivated, ['X-WC-Webhook-Topic' => 'order.updated', 'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $reactivated, EC_SECRET, true))])->assertOk();

    expect(ecStock($this->mug))->toBe(8.0);
});

test('shopify webhooks are verified and imported', function (): void {
    $channel = ecChannel('shopify');
    ecMap($channel, $this->mug, 'product', '300', ['inventory_item_id' => '4000']);

    $body = json_encode([
        'id' => 6001, 'name' => '#6001', 'currency' => 'EUR', 'created_at' => '2026-10-20T10:00:00+02:00', 'taxes_included' => false, 'financial_status' => 'paid',
        'email' => 'hook@example.com', 'billing_address' => ['first_name' => 'Gina', 'last_name' => 'Neri', 'country_code' => 'IT'],
        'line_items' => [['product_id' => 300, 'variant_id' => 301, 'sku' => 'MUG-1', 'name' => 'Mug', 'quantity' => 1, 'price' => '10.00', 'tax_lines' => [['rate' => 0.22]]]],
    ]);

    $headers = ['X-Shopify-Topic' => 'orders/create', 'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, EC_SECRET, true))];

    ecSignedPost($channel, $body, $headers)->assertOk()->assertJsonPath('data.imported', 1);
    expect(ecStock($this->mug))->toBe(9.0);
});

test('prestashop webhook relays are verified and the order is re-fetched', function (): void {
    $channel = ecChannel('prestashop');
    ecMap($channel, $this->mug, 'product', '12');

    Http::fake([
        'presta.example.com/api/orders/7*' => Http::response(['order' => [
            'id' => 7, 'reference' => 'ABCDEF', 'id_customer' => 3, 'id_address_invoice' => 4, 'id_currency' => 1, 'current_state' => 2, 'date_add' => '2026-10-20 10:00:00',
            'associations' => ['order_rows' => [['product_id' => 12, 'product_attribute_id' => 0, 'product_reference' => 'MUG-1', 'product_name' => 'Mug', 'product_quantity' => 1, 'unit_price_tax_excl' => '10.000000']]],
        ]]),
        'presta.example.com/api/customers/3*' => Http::response(['customer' => ['id' => 3, 'email' => 'p@example.com', 'firstname' => 'P', 'lastname' => 'Q']]),
        'presta.example.com/api/addresses/4*' => Http::response(['address' => ['id' => 4, 'address1' => 'Via Po 2', 'postcode' => '10100', 'city' => 'Torino', 'id_country' => 10]]),
        'presta.example.com/api/countries/10*' => Http::response(['country' => ['id' => 10, 'iso_code' => 'IT']]),
        'presta.example.com/api/currencies/1*' => Http::response(['currency' => ['id' => 1, 'iso_code' => 'EUR']]),
    ]);

    $body = json_encode(['event' => 'order.created', 'id_order' => 7]);

    ecSignedPost($channel, $body, ['X-Presta-Signature' => hash_hmac('sha256', $body, EC_SECRET)])->assertOk()->assertJsonPath('data.imported', 1);
    expect(ecStock($this->mug))->toBe(9.0);
});

test('webhooks with a bad, missing or tampered signature are rejected without side effects', function (string $driver, string $header, bool $base64): void {
    $channel = ecChannel($driver);
    $body = json_encode(ecWooOrder(8001, [ecWooItem('MUG-1', 1, 0, 1)]));
    $signature = fn (string $payload, string $secret): string => $base64 ? base64_encode(hash_hmac('sha256', $payload, $secret, true)) : hash_hmac('sha256', $payload, $secret);

    ecSignedPost($channel, $body, [$header => $signature($body, 'wrong-secret-0123456789')])->assertUnauthorized();
    ecSignedPost($channel, $body, [])->assertUnauthorized();
    ecSignedPost($channel, $body.' ', [$header => $signature($body, EC_SECRET)])->assertUnauthorized();

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
})->with([
    'woocommerce' => ['woocommerce', 'X-WC-Webhook-Signature', true],
    'shopify' => ['shopify', 'X-Shopify-Hmac-Sha256', true],
    'prestashop' => ['prestashop', 'X-Presta-Signature', false],
]);

test('non order webhook topics are acknowledged and ignored', function (): void {
    $channel = ecChannel('woocommerce');
    $body = json_encode(['webhook_id' => 1]);

    ecSignedPost($channel, $body, ['X-WC-Webhook-Topic' => 'product.updated', 'X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, EC_SECRET, true))])
        ->assertOk()->assertJsonPath('data.events', 0);
});

test('webhooks for unknown, malformed or inactive channels are not found and an empty secret is rejected', function (): void {
    $channel = ecChannel('woocommerce');
    $body = '{}';

    test()->call('POST', '/api/v1/webhooks/ecommerce/'.Str::uuid(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertNotFound();
    test()->call('POST', '/api/v1/webhooks/ecommerce/not-a-uuid', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertNotFound();

    $channel->update(['webhook_secret' => '']);
    ecSignedPost($channel, $body, ['X-WC-Webhook-Signature' => base64_encode(hash_hmac('sha256', $body, '', true))])->assertUnauthorized();

    $channel->update(['is_active' => false]);
    ecSignedPost($channel, $body, [])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------
// Command and manager
// ---------------------------------------------------------------------------------------------------------------

test('the sync command imports orders for every tenant and survives a failing channel', function (): void {
    $good = ecChannel('woocommerce');
    $other = ecChannel('woocommerce', ['name' => 'Other woo', 'api_url' => 'https://other.example.com'], $this->otherTenant->id);
    $broken = ecChannel('prestashop');
    ecChannel('woocommerce', ['name' => 'Inactive', 'is_active' => false, 'api_url' => 'https://inactive.example.com']);
    ecChannel('woocommerce', ['name' => 'No orders', 'sync_orders_enabled' => false, 'api_url' => 'https://noorders.example.com']);

    Http::fake([
        'woo.example.com/*' => Http::response([ecWooOrder(9001, [ecWooItem('MUG-1', 1, 0, 1)])]),
        'other.example.com/*' => Http::response([ecWooOrder(9002, [ecWooItem('X', 1, 0, 1)], 'processing', 'foreign@example.com')]),
        'presta.example.com/*' => Http::response('boom', 500),
    ]);

    $this->artisan('ecommerce:sync-orders')->assertExitCode(1);

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(2)
        ->and(SalesOrder::withoutGlobalScopes()->where('tenant_id', $this->otherTenant->id)->count())->toBe(1)
        ->and(EcommerceChannel::withoutGlobalScopes()->find($good->id)->last_sync_at)->not->toBeNull()
        ->and(EcommerceChannel::withoutGlobalScopes()->find($other->id)->last_sync_at)->not->toBeNull()
        ->and(EcommerceChannel::withoutGlobalScopes()->find($broken->id)->last_sync_at)->toBeNull();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'inactive.example.com') || str_contains($request->url(), 'noorders.example.com'));
});

test('the manager resolves built-in drivers, accepts custom ones and rejects unknown ones', function (): void {
    $manager = app(EcommerceManager::class);

    expect($manager->drivers())->toContain('woocommerce', 'shopify', 'prestashop')
        ->and($manager->driver(ecChannel('shopify')))->toBeInstanceOf(ShopifyDriver::class);

    $manager->extend('custom', fn (EcommerceChannel $channel) => new class($channel) extends WooCommerceDriver {});
    $custom = ecChannel('woocommerce', ['name' => 'Custom', 'driver' => 'custom']);
    expect($manager->driver($custom))->toBeInstanceOf(WooCommerceDriver::class);

    $unknown = ecChannel('woocommerce', ['name' => 'Unknown', 'driver' => 'magento']);
    expect(fn () => $manager->driver($unknown))->toThrow(EcommerceException::class);
});
