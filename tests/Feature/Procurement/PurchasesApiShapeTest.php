<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Shape SRL', 'legal_name' => 'Shape SRL', 'vat_number' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->user = User::create(['name' => 'Buyer', 'email' => 'buyer@shape.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->supplier = Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'supplier', 'company_name' => 'Acme Supplies SRL', 'country' => 'IT', 'vat_number' => '01234567890']);
});

function shapeApi(): mixed
{
    Sanctum::actingAs(test()->user);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

test('the legacy purchases lists keep the paginator inside the data of the envelope', function (): void {
    $product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'PMP-1', 'name' => 'Pump', 'cost_price' => 0, 'sale_price' => 90, 'tax_rate' => 22]);
    $order = shapeApi()->postJson('/api/v1/purchases/orders', [
        'supplier_id' => $this->supplier->id, 'date' => '2026-03-10', 'currency' => 'EUR',
        'items' => [['product_id' => $product->id, 'description' => 'Pump', 'quantity' => 10, 'unit_price' => 40, 'tax_rate' => 22]],
    ])->assertCreated();
    PurchaseRequest::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'request_number' => 'PR-1', 'status' => 'draft', 'total_estimated' => 10, 'requested_date' => '2026-03-01']);
    DeliveryNote::create(['tenant_id' => $this->tenant->id, 'contact_id' => $this->supplier->id, 'ddt_number' => 'DDT-1', 'date' => '2026-03-02']);

    $paginatorKeys = ['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'];

    foreach (['/api/v1/purchases/orders' => 'PO-', '/api/v1/purchases/requests' => 'PR-', '/api/v1/inventory/delivery-notes' => 'DDT-'] as $url => $prefix) {
        $response = shapeApi()->getJson($url)->assertOk();

        expect(array_keys($response->json()))->toBe(['success', 'message', 'data'])
            ->and(array_keys($response->json('data')))->toBe($paginatorKeys)
            ->and($response->json('data.data'))->toHaveCount(1)
            ->and($response->json('data.total'))->toBe(1);
    }

    $first = shapeApi()->getJson('/api/v1/purchases/orders')->json('data.data.0');
    expect($first['id'])->toBe($order->json('data.id'))
        ->and($first)->toHaveKeys(['order_number', 'total_net', 'total_tax', 'total_gross', 'supplier', 'items'])
        ->and($first['total_gross'])->toBe('488.00')
        ->and($first['items'][0])->toHaveKeys(['id', 'purchase_order_id', 'product_id', 'description', 'quantity', 'unit_price', 'tax_rate', 'line_total']);
    expect(shapeApi()->getJson('/api/v1/purchases/requests')->json('data.data.0'))->toHaveKeys(['request_number', 'department', 'status', 'total_estimated', 'requested_date', 'user']);
    expect(shapeApi()->getJson('/api/v1/inventory/delivery-notes')->json('data.data.0'))->toHaveKeys(['ddt_number', 'contact_id', 'status', 'shipping_status', 'transport_reason']);
});

test('the legacy goods receipts list is a plain page with a short meta', function (): void {
    $response = shapeApi()->getJson('/api/v1/purchases/goods-receipts')->assertOk();

    expect(array_keys($response->json()))->toBe(['success', 'message', 'data', 'meta'])
        ->and($response->json('data'))->toBe([])
        ->and(array_keys($response->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total']);
});

test('the procurement lists are resource collections with data, links and meta', function (): void {
    foreach (['purchase-orders', 'requisitions', 'contracts', 'goods-receipts', 'three-way-match'] as $list) {
        $response = shapeApi()->getJson("/api/v1/procurement/{$list}")->assertOk();

        expect(array_keys($response->json()))->toBe(['data', 'links', 'meta'], $list)
            ->and(array_keys($response->json('links')))->toBe(['first', 'last', 'prev', 'next'])
            ->and(array_keys($response->json('meta')))->toBe(['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']);
    }
});

test('converting suggestions and approving a match variance validate their own body', function (): void {
    shapeApi()->postJson('/api/v1/procurement/purchase-suggestions/convert', [])->assertUnprocessable()->assertJsonValidationErrors('suggestion_ids');
    shapeApi()->postJson('/api/v1/procurement/purchase-suggestions/convert', ['suggestion_ids' => ['not-a-uuid']])->assertUnprocessable()->assertJsonValidationErrors('suggestion_ids.0');
    shapeApi()->postJson('/api/v1/procurement/three-way-match/'.Str::uuid7().'/approve-variance', [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    shapeApi()->postJson('/api/v1/procurement/three-way-match/'.Str::uuid7().'/approve-variance', ['reason' => 'Agreed with the supplier'])->assertNotFound();
});

test('a reader without the procurement module cannot convert suggestions or approve variances', function (): void {
    $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@shape.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $viewer->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'viewer']);
    Sanctum::actingAs($viewer);

    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/procurement/purchase-suggestions/convert', ['suggestion_ids' => [(string) Str::uuid7()]])->assertForbidden();
    $this->withHeader('X-Tenant-Id', $this->tenant->id)->postJson('/api/v1/procurement/three-way-match/'.Str::uuid7().'/approve-variance', ['reason' => 'x'])->assertForbidden();
});
