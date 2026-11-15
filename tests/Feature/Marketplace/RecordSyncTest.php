<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Marketplace\Mappers\EcommerceRecordMapper;
use Modules\Marketplace\Mappers\PaymentRecordMapper;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceStagedRecord;
use Modules\Marketplace\Models\MarketplaceSyncFailure;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Services\SyncService;
use Modules\Marketplace\Support\MappedRecord;
use Modules\Marketplace\Support\RecordMapperRegistry;

require_once __DIR__.'/../Analytics/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    biActAs($this->tenant, 'owner');
    $this->secret = Str::random(40);
    $this->answer = new ArrayObject(['body' => [], 'requests' => [], 'transactionLevels' => []]);
    Http::fake(function (Request $request) {
        $this->answer['requests'] = [...$this->answer['requests'], $request->url()];
        $this->answer['transactionLevels'] = [...$this->answer['transactionLevels'], DB::transactionLevel()];

        return Http::response($this->answer['body'], 200);
    });
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $settings
 * @param  array<string, string>  $credentials
 */
function rsInstallation(string $key, array $settings, array $credentials, array $scopes): MarketplaceInstallation
{
    return MarketplaceInstallation::factory()->enabled()->create([
        'tenant_id' => test()->tenant->id,
        'connector_key' => $key,
        'settings' => $settings,
        'credentials' => $credentials,
        'granted_scopes' => $scopes,
    ]);
}

function rsShopify(): MarketplaceInstallation
{
    return rsInstallation('shopify', ['shop_domain' => 'mystore.myshopify.com', 'api_version' => '2025-01'], ['access_token' => test()->secret], ['invoicing.write', 'inventory.write', 'crm.write']);
}

function rsStripe(): MarketplaceInstallation
{
    return rsInstallation('stripe', [], ['secret_key' => test()->secret], ['payment_links.write', 'treasury.write', 'invoicing.read']);
}

/**
 * @param  list<array<string, mixed>>  $records
 */
function rsPull(array $records, string $collection = 'orders'): void
{
    test()->answer['body'] = [$collection => $records];
}

/**
 * @return array<string, mixed>
 */
function rsShopifyOrder(int $id, string $total = '120.50'): array
{
    return [
        'id' => $id, 'name' => "#{$id}", 'financial_status' => 'paid', 'currency' => 'eur', 'total_price' => $total,
        'created_at' => '2027-03-14T09:30:00+01:00', 'email' => 'Buyer@Example.com',
        'customer' => ['id' => 77, 'email' => 'buyer@example.com', 'first_name' => 'Ada', 'last_name' => 'Rossi'],
        'line_items' => [['sku' => 'SKU-1', 'title' => 'Chair', 'quantity' => 2, 'price' => '60.25']],
    ];
}

test('the e-commerce mapper normalises Shopify and WooCommerce orders, products and customers', function (): void {
    $mapper = new EcommerceRecordMapper;

    expect($mapper->supports('shopify', 'orders'))->toBeTrue()->and($mapper->supports('stripe', 'orders'))->toBeFalse()->and($mapper->supports('shopify', 'shipments'))->toBeFalse();

    $order = $mapper->map('shopify', 'orders', rsShopifyOrder(1001));
    expect($order->entityType)->toBe(MappedRecord::Order)->and($order->externalId)->toBe('1001')
        ->and($order->payload['currency'])->toBe('EUR')->and($order->payload['total'])->toBe('120.50')
        ->and($order->payload['ordered_at'])->toBe('2027-03-14T08:30:00Z')
        ->and($order->payload['customer'])->toBe(['external_id' => '77', 'email' => 'Buyer@Example.com', 'name' => 'Ada Rossi'])
        ->and($order->payload['lines'])->toBe([['sku' => 'SKU-1', 'name' => 'Chair', 'quantity' => 2, 'unit_price' => '60.25']]);

    $woo = $mapper->map('woocommerce', 'orders', [
        'id' => 55, 'number' => '55', 'status' => 'processing', 'currency' => 'CHF', 'total' => '10.00', 'date_created_gmt' => '2027-03-01T10:00:00',
        'customer_id' => 4, 'billing' => ['email' => 'w@example.com', 'first_name' => 'Wo', 'last_name' => 'Man'],
        'line_items' => [['sku' => '', 'name' => 'Pen', 'quantity' => 1, 'price' => 10]],
    ]);
    expect($woo->payload['customer']['external_id'])->toBe('4')->and($woo->payload['lines'][0]['sku'])->toBeNull()->and($woo->payload['lines'][0]['unit_price'])->toBe('10.00');
    expect($mapper->cursorOf('woocommerce', 'orders', ['date_created_gmt' => '2027-03-01T10:00:00']))->toBe('2027-03-01T10:00:00Z')
        ->and($mapper->cursorOf('shopify', 'orders', ['id' => 9]))->toBe('9');

    $product = $mapper->map('shopify', 'products', ['id' => 3, 'title' => 'Chair', 'variants' => [['sku' => 'SKU-1', 'price' => '60.25']]]);
    expect($product->payload)->toBe(['name' => 'Chair', 'sku' => 'SKU-1', 'price' => '60.25']);
    expect($mapper->map('woocommerce', 'customers', ['id' => 8, 'email' => 'X@Example.com', 'first_name' => 'X']))->toMatchObject(['entityType' => 'customer'])
        ->and($mapper->map('woocommerce', 'customers', ['id' => 8, 'email' => 'X@Example.com'])->payload['email'])->toBe('x@example.com');
});

test('the payment mapper normalises Stripe and Mollie records', function (): void {
    $mapper = new PaymentRecordMapper;

    $charge = $mapper->map('stripe', 'charges', ['id' => 'ch_1', 'amount' => 12345, 'currency' => 'eur', 'status' => 'succeeded', 'paid' => true, 'customer' => 'cus_1', 'created' => 1805000000]);
    expect($charge->entityType)->toBe(MappedRecord::Payment)->and($charge->payload['amount'])->toBe('123.45')->and($charge->payload['currency'])->toBe('EUR')
        ->and($charge->payload['paid'])->toBeTrue()->and($charge->payload['customer_external_id'])->toBe('cus_1')
        ->and($mapper->cursorOf('stripe', 'charges', ['created' => 1805000000]))->toBe('1805000000');

    expect($mapper->map('stripe', 'payouts', ['id' => 'po_1', 'amount' => 5000, 'currency' => 'jpy', 'arrival_date' => 1805000000])->payload['amount'])->toBe('5000');

    $payment = $mapper->map('mollie', 'payments', ['id' => 'tr_1', 'amount' => ['value' => '10.00', 'currency' => 'EUR'], 'status' => 'paid', 'createdAt' => '2027-03-01T10:00:00+00:00']);
    expect($payment->payload['paid'])->toBeTrue()->and($payment->payload['amount'])->toBe('10.00')->and($mapper->cursorOf('mollie', 'payments', ['id' => 'tr_1']))->toBeNull();

    $settlement = $mapper->map('mollie', 'settlements', ['id' => 'stl_1', 'reference' => '123.2027.03', 'amount' => ['value' => '99.90', 'currency' => 'EUR'], 'settledAt' => '2027-03-02T00:00:00+00:00']);
    expect($settlement->entityType)->toBe(MappedRecord::Payout)->and($settlement->payload['reference'])->toBe('123.2027.03');

    expect(fn () => $mapper->map('stripe', 'charges', ['id' => 'ch_2', 'currency' => 'eur']))->toThrow(RuntimeException::class);
    expect(fn () => $mapper->map('stripe', 'charges', ['currency' => 'eur', 'amount' => 1]))->toThrow(RuntimeException::class);
});

test('a sync maps the pulled records into the staging table and a re-sync does not duplicate them', function (): void {
    $installation = rsShopify();
    rsPull([rsShopifyOrder(1001), rsShopifyOrder(1002)]);

    $first = app(SyncService::class)->run($installation, 'orders');
    expect($first->status)->toBe(MarketplaceSyncRun::Succeeded)->and($first->records_processed)->toBe(2)->and($first->records_failed)->toBe(0);
    expect(MarketplaceStagedRecord::query()->count())->toBe(2)
        ->and(MarketplaceStagedRecord::query()->where('external_id', '1001')->first()->only(['entity_type', 'status', 'resource']))->toBe(['entity_type' => 'order', 'status' => 'pending', 'resource' => 'orders']);

    $second = app(SyncService::class)->run($installation->refresh(), 'orders');
    expect($second->records_processed)->toBe(2)->and(MarketplaceStagedRecord::query()->count())->toBe(2);

    $hash = MarketplaceStagedRecord::query()->where('external_id', '1001')->value('content_hash');
    MarketplaceStagedRecord::query()->where('external_id', '1001')->update(['status' => MarketplaceStagedRecord::Processed]);

    rsPull([rsShopifyOrder(1001), rsShopifyOrder(1002, '99.00')]);
    app(SyncService::class)->run($installation->refresh(), 'orders');

    $unchanged = MarketplaceStagedRecord::query()->where('external_id', '1001')->first();
    $changed = MarketplaceStagedRecord::query()->where('external_id', '1002')->first();
    expect(MarketplaceStagedRecord::query()->count())->toBe(2)
        ->and($unchanged->content_hash)->toBe($hash)->and($unchanged->status)->toBe(MarketplaceStagedRecord::Processed)
        ->and($changed->payload['total'])->toBe('99.00')->and($changed->status)->toBe(MarketplaceStagedRecord::Pending);

    expect(MarketplaceStagedRecord::query()->pluck('idempotency_key')->unique())->toHaveCount(2);
});

test('the cursor is stored on the installation, advances and is sent on the next pull', function (): void {
    $installation = rsShopify();
    rsPull([rsShopifyOrder(1001), rsShopifyOrder(1005), rsShopifyOrder(1003)]);

    app(SyncService::class)->run($installation, 'orders');
    expect($installation->refresh()->sync_cursors)->toBe(['orders' => '1005'])->and($this->answer['requests'][0])->not->toContain('since_id');

    rsPull([rsShopifyOrder(1006)]);
    app(SyncService::class)->run($installation, 'orders');
    expect($this->answer['requests'][1])->toContain('since_id=1005')->and($installation->refresh()->sync_cursors)->toBe(['orders' => '1006']);

    rsPull([]);
    app(SyncService::class)->run($installation, 'orders');
    expect($installation->refresh()->sync_cursors)->toBe(['orders' => '1006']);

    rsPull([['id' => 5, 'title' => 'Chair', 'variants' => [['price' => '1.00']]]], 'products');
    app(SyncService::class)->run($installation, 'products');
    expect($installation->refresh()->sync_cursors)->toBe(['orders' => '1006', 'products' => '5']);
});

test('Stripe charges resume after the newest created timestamp', function (): void {
    $installation = rsStripe();
    rsPull([['id' => 'ch_a', 'amount' => 1000, 'currency' => 'eur', 'created' => 1805000100], ['id' => 'ch_b', 'amount' => 2000, 'currency' => 'eur', 'created' => 1805000300]], 'data');

    app(SyncService::class)->run($installation, 'charges');
    app(SyncService::class)->run($installation->refresh(), 'charges');

    expect($installation->refresh()->sync_cursors)->toBe(['charges' => '1805000300'])
        ->and(urldecode($this->answer['requests'][1]))->toContain('created[gt]=1805000300')
        ->and(MarketplaceStagedRecord::query()->where('entity_type', 'payment')->count())->toBe(2);
});

test('a record that cannot be mapped is recorded as a failure and the run goes on', function (): void {
    $installation = rsShopify();
    $broken = rsShopifyOrder(1002);
    $broken['total_price'] = 'not-a-number';
    $broken['note'] = $this->secret;
    rsPull([rsShopifyOrder(1001), $broken, ['name' => 'no id'], rsShopifyOrder(1003)]);

    $run = app(SyncService::class)->run($installation, 'orders');

    expect($run->status)->toBe(MarketplaceSyncRun::Succeeded)->and($run->records_processed)->toBe(4)->and($run->records_failed)->toBe(2)
        ->and(MarketplaceStagedRecord::query()->pluck('external_id')->sort()->values()->all())->toBe(['1001', '1003']);

    $failures = MarketplaceSyncFailure::query()->where('sync_run_id', $run->id)->orderBy('created_at')->get();
    expect($failures)->toHaveCount(2)->and($failures->pluck('external_id')->all())->toContain('1002')
        ->and($failures->pluck('message')->implode(' '))->not->toContain($this->secret);
    expect($installation->refresh()->sync_cursors)->toBe(['orders' => '1003']);
});

test('the external call is never made inside a database transaction', function (): void {
    $installation = rsShopify();
    rsPull([rsShopifyOrder(1001)]);

    app(SyncService::class)->run($installation, 'orders');

    expect($this->answer['transactionLevels'])->not->toBeEmpty();

    $baseline = DB::transactionLevel();
    expect(collect($this->answer['transactionLevels'])->every(fn (int $level): bool => $level === $baseline))->toBeTrue();
});

test('a connector without a mapper for the resource keeps its own sync and the registry accepts new mappers', function (): void {
    $installation = rsInstallation('mollie', [], ['api_key' => $this->secret], ['payment_links.write', 'treasury.write', 'invoicing.read']);
    $this->answer['body'] = ['_embedded' => ['payments' => [['id' => 'tr_1', 'amount' => ['value' => '5.00', 'currency' => 'EUR'], 'status' => 'paid']]]];

    $run = app(SyncService::class)->run($installation, 'payments');
    expect($run->records_processed)->toBe(1)->and(MarketplaceStagedRecord::query()->where('connector_key', 'mollie')->count())->toBe(1);

    $registry = app(RecordMapperRegistry::class);
    expect($registry->find('shopify', 'orders'))->toBeInstanceOf(EcommerceRecordMapper::class)
        ->and($registry->find('stripe', 'charges'))->toBeInstanceOf(PaymentRecordMapper::class)
        ->and($registry->find('shipstation', 'orders'))->toBeNull();
});
