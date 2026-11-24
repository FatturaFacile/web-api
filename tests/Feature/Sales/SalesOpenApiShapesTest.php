<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-03-15 10:00:00');

    $this->tenant = Tenant::create(['name' => 'Shapes SRL', 'legal_name' => 'Shapes SRL', 'vat_number' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, 2026);

    $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@shapes.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->admin->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->admin);

    $this->contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Shape Customer SpA', 'country' => 'IT']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SHP-1', 'name' => 'Widget', 'cost_price' => 40, 'sale_price' => 100, 'tax_rate' => 22, 'track_inventory' => false]);
});

function shapesApi(): mixed
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

/**
 * @return array<string, mixed>
 */
function shapesDocument(string $kind): array
{
    return [
        'contact_id' => test()->contact->id, 'date' => '2026-03-15', 'currency' => 'EUR', 'status' => $kind === 'order' ? 'confirmed' : 'draft',
        'items' => [['product_id' => test()->product->id, 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 22]],
    ];
}

test('order lines carry the delivered, reserved and invoiced quantities and quote lines do not', function (): void {
    $order = shapesApi()->postJson('/api/v1/sales/orders', shapesDocument('order'))->assertCreated()->json('data');
    $quote = shapesApi()->postJson('/api/v1/sales/quotes', shapesDocument('quote'))->assertCreated()->json('data');

    foreach (['quantity_delivered', 'quantity_reserved', 'quantity_invoiced'] as $key) {
        expect($order['items'][0])->toHaveKey($key)->and($order['items'][0][$key])->toBe('0.0000')
            ->and($quote['items'][0])->not->toHaveKey($key);
    }

    $show = shapesApi()->getJson('/api/v1/sales/orders/'.$order['id'])->assertOk()->json('data');
    expect($show['items'][0]['quantity_invoiced'])->toBe('0.0000')
        ->and($show['credit_hold_id'])->toBeNull()
        ->and($show['holds'])->toBe([])
        ->and($show['total_gross'])->toBe('244.00');

    $list = shapesApi()->getJson('/api/v1/sales/orders')->assertOk();
    expect($list->json('data.0'))->not->toHaveKey('items')
        ->and(array_keys($list->json('meta')))->toBe(['current_page', 'last_page', 'per_page', 'total']);
});

test('the availability of an order is an object with order, warehouse, flag and lines', function (): void {
    $order = shapesApi()->postJson('/api/v1/sales/orders', shapesDocument('order'))->assertCreated()->json('data');

    $availability = shapesApi()->getJson('/api/v1/sales/orders/'.$order['id'].'/availability')->assertOk();
    expect(array_keys($availability->json('data')))->toBe(['sales_order_id', 'warehouse_id', 'fully_available', 'lines'])
        ->and($availability->json('data.lines'))->toBeArray();
});

test('billing plans are listed as a Laravel paginator', function (): void {
    $list = shapesApi()->getJson('/api/v1/invoicing/billing-plans?per_page=5')->assertOk();

    expect(array_keys($list->json()))->toBe(['data', 'links', 'meta'])
        ->and(array_keys($list->json('links')))->toBe(['first', 'last', 'prev', 'next'])
        ->and($list->json('meta.per_page'))->toBe(5)
        ->and($list->json('meta'))->toHaveKeys(['current_page', 'from', 'last_page', 'links', 'path', 'per_page', 'to', 'total']);
});

test('segment filters are objects with field, operator and value, never positional triples', function (): void {
    $valid = ['name' => 'Italian customers', 'entity' => 'contacts', 'filters' => [
        ['field' => 'country', 'operator' => 'eq', 'value' => 'IT'],
        ['field' => 'type', 'operator' => 'in', 'value' => ['client', 'prospect']],
        ['field' => 'email', 'operator' => 'not_null'],
        ['field' => 'consent', 'channel' => 'email', 'purpose' => 'marketing'],
    ]];
    $created = shapesApi()->postJson('/api/v1/crm/segments', $valid)->assertCreated();
    expect($created->json('filters'))->toBe($valid['filters']);

    shapesApi()->postJson('/api/v1/crm/segments', ['name' => 'Triples', 'entity' => 'contacts', 'filters' => [['country', 'eq', 'IT']]])
        ->assertUnprocessable()->assertJsonValidationErrors('filters');
    shapesApi()->postJson('/api/v1/crm/segments', ['name' => 'Bad operator', 'entity' => 'contacts', 'filters' => [['field' => 'country', 'operator' => 'like', 'value' => 'I']]])
        ->assertUnprocessable()->assertJsonValidationErrors('filters');
    shapesApi()->postJson('/api/v1/crm/segments', ['name' => 'Bad field', 'entity' => 'contacts', 'filters' => [['field' => 'password', 'operator' => 'eq', 'value' => 'x']]])
        ->assertUnprocessable()->assertJsonValidationErrors('filters');
    shapesApi()->postJson('/api/v1/crm/segments', ['name' => 'Lead field on contacts', 'entity' => 'contacts', 'filters' => [['field' => 'rating', 'operator' => 'eq', 'value' => 'hot']]])
        ->assertUnprocessable();
});

test('the CRM reports have the documented keys', function (): void {
    expect(array_keys(shapesApi()->getJson('/api/v1/crm/reports/pipeline')->assertOk()->json()))->toBe(['by_stage', 'by_owner', 'totals']);
    expect(array_keys(shapesApi()->getJson('/api/v1/crm/reports/conversion')->assertOk()->json()))->toBe(['leads', 'opportunities']);
    expect(array_keys(shapesApi()->getJson('/api/v1/crm/reports/conversion')->json('leads')))->toBe(['total', 'by_status', 'qualified_or_converted', 'converted', 'qualification_rate', 'conversion_rate', 'average_days_to_convert', 'by_source']);
    expect(array_keys(shapesApi()->getJson('/api/v1/crm/reports/activities')->assertOk()->json()))->toBe(['total', 'completed', 'overdue', 'completion_rate', 'by_status', 'by_type', 'by_assignee']);
});

test('sales documents and CRM reports of another company are not visible', function (): void {
    $order = shapesApi()->postJson('/api/v1/sales/orders', shapesDocument('order'))->assertCreated()->json('data');

    $other = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@shapes.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $stranger->tenants()->attach($other->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($stranger);
    $foreign = fn () => $this->withHeader('X-Tenant-Id', $other->id);

    $foreign()->getJson('/api/v1/sales/orders/'.$order['id'])->assertNotFound();
    $foreign()->getJson('/api/v1/sales/orders/'.$order['id'].'/availability')->assertNotFound();
    expect($foreign()->getJson('/api/v1/invoicing/billing-plans')->assertOk()->json('data'))->toBe([]);
    expect($foreign()->getJson('/api/v1/crm/reports/pipeline')->assertOk()->json('totals.opportunities'))->toBe(0);
});

test('a viewer cannot create orders or segments', function (): void {
    $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@shapes.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $viewer->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'viewer']);
    Sanctum::actingAs($viewer);

    shapesApi()->postJson('/api/v1/sales/orders', shapesDocument('order'))->assertForbidden();
    shapesApi()->postJson('/api/v1/crm/segments', ['name' => 'X', 'entity' => 'contacts', 'filters' => []])->assertForbidden();
});
