<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesOrderHold;

uses(RefreshDatabase::class);

/**
 * HTTP behaviour of the sales orders and of the customer credit of the invoicing module: totals computed per VAT rate,
 * the credit limit that puts an order on hold, the release of the hold, the filters of the list, validation and the
 * boundary between tenants and between roles.
 *
 * @return array{tenant: Tenant, user: User, customer: Contact, product: Product}
 */
function orderWorld(string $role = 'owner'): array
{
    $tenant = Tenant::create(['name' => 'Orders '.Str::random(4), 'legal_name' => 'Orders SA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => 'Sales', 'email' => Str::random(10).'@example.test', 'password' => bcrypt(Str::password(24)), 'locale' => 'it']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);
    app(TenantContext::class)->setTenant($tenant);

    return [
        'tenant' => $tenant,
        'user' => $user,
        'customer' => Contact::create(['tenant_id' => $tenant->id, 'type' => 'client', 'company_name' => 'Cliente '.Str::random(4), 'country' => 'IT']),
        'product' => Product::create(['tenant_id' => $tenant->id, 'sku' => 'SKU-'.Str::random(5), 'name' => 'Server', 'sale_price' => 10000, 'cost_price' => 6000, 'tax_rate' => 22.0]),
    ];
}

/**
 * @param  array<string, mixed>  $world
 */
function orderApi(array $world): mixed
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($world['user']);

    return test()->withHeader('X-Tenant-Id', (string) $world['tenant']->id);
}

/**
 * @param  array<string, mixed>  $world
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function orderPayload(array $world, array $overrides = []): array
{
    return [
        'contact_id' => (string) $world['customer']->id,
        'auto_submit' => false,
        'items' => [['product_id' => (string) $world['product']->id, 'description' => 'Server', 'quantity' => 2, 'unit_price' => 10000, 'tax_rate' => 22]],
        ...$overrides,
    ];
}

test('an order is created with the totals computed per VAT rate on the net of its lines', function (): void {
    $world = orderWorld();

    $response = orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, [
        'items' => [
            ['description' => 'Hardware', 'quantity' => 2, 'unit_price' => 10000, 'tax_rate' => 22],
            ['description' => 'Training', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 10, 'discount_percentage' => 10],
        ],
    ]))->assertCreated();

    // Net: 2 x 10,000 = 20,000 and 1 x 1,000 less 10% = 900 -> 20,900. VAT: 20,000 x 22% = 4,400 and 900 x 10% = 90 -> 4,490.
    expect($response->json('total_net'))->toEqual(20900)
        ->and($response->json('total_tax'))->toEqual(4490)
        ->and($response->json('total_gross'))->toEqual(25390)
        ->and($response->json('status'))->not->toBe('cancelled')
        ->and($response->json('items'))->toHaveCount(2)
        ->and($response->json('contact_id'))->toBe((string) $world['customer']->id)
        ->and(SalesOrder::query()->count())->toBe(1);
});

test('an order needs lines and a customer of the tenant', function (): void {
    $world = orderWorld();
    $other = orderWorld();

    orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, ['items' => []]))->assertUnprocessable()->assertJsonValidationErrors('items');
    orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, ['contact_id' => (string) $other['customer']->id]))->assertUnprocessable()->assertJsonValidationErrors('contact_id');
    orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, ['items' => [['description' => 'X', 'quantity' => 0, 'tax_rate' => 22]]]))->assertUnprocessable();
    orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, ['items' => [['product_id' => (string) $other['product']->id, 'description' => 'X', 'quantity' => 1, 'tax_rate' => 22]]]))->assertUnprocessable();

    expect(SalesOrder::withoutGlobalScopes()->count())->toBe(0);
});

test('the list is filtered by status and customer and shows the own tenant only', function (): void {
    $world = orderWorld();
    $other = orderWorld();
    app(TenantContext::class)->setTenant($world['tenant']);
    $second = Contact::create(['tenant_id' => $world['tenant']->id, 'type' => 'client', 'company_name' => 'Secondo', 'country' => 'IT']);

    $first = orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world))->assertCreated()->json('id');
    $other2 = orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, ['contact_id' => (string) $second->id]))->assertCreated()->json('id');
    orderApi($other)->postJson('/api/v1/invoicing/orders', orderPayload($other))->assertCreated();

    $all = orderApi($world)->getJson('/api/v1/invoicing/orders')->assertOk();
    expect(array_column($all->json('data'), 'id'))->toEqualCanonicalizing([$first, $other2]);

    $byCustomer = orderApi($world)->getJson('/api/v1/invoicing/orders?contact_id='.$second->id)->assertOk();
    expect(array_column($byCustomer->json('data'), 'id'))->toBe([$other2]);

    expect(orderApi($world)->getJson('/api/v1/invoicing/orders?per_page=100000')->assertOk()->json('meta.per_page'))->toBe(200)
        ->and(orderApi($world)->getJson('/api/v1/invoicing/orders?per_page=0')->assertOk()->json('meta.per_page'))->toBe(1);

    $none = orderApi($world)->getJson('/api/v1/invoicing/orders?status=shipped')->assertOk();
    expect($none->json('data'))->toBe([]);

    orderApi($world)->getJson('/api/v1/invoicing/orders/'.$first)->assertOk()->assertJsonPath('id', $first);
    orderApi($other)->getJson('/api/v1/invoicing/orders/'.$first)->assertNotFound();
    orderApi($other)->postJson('/api/v1/invoicing/orders/'.$first.'/cancel', [])->assertNotFound();
    orderApi($other)->postJson('/api/v1/invoicing/orders/'.$first.'/approve', [])->assertNotFound();
});

test('a draft order is cancelled with the reason given', function (): void {
    $world = orderWorld();
    $id = orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world))->assertCreated()->json('id');

    $response = orderApi($world)->postJson("/api/v1/invoicing/orders/{$id}/cancel", ['reason' => 'Customer withdrew'])->assertOk();

    expect($response->json('status'))->toBe('cancelled')
        ->and(SalesOrder::query()->findOrFail($id)->status)->toBe('cancelled');
});

test('the credit limit of a customer is set, validated and tenant bound', function (): void {
    $world = orderWorld();
    $other = orderWorld();

    $response = orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $world['customer']->id, 'credit_limit' => 15000])->assertOk();
    expect($response->json('credit_limit'))->toEqual(15000)
        ->and($response->json('customer_id'))->toBe((string) $world['customer']->id)
        ->and($response->json('tenant_id'))->toBe((string) $world['tenant']->id);

    orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $world['customer']->id, 'credit_limit' => -1])->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
    orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $world['customer']->id, 'credit_limit' => '10.005'])->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
    orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $other['customer']->id, 'credit_limit' => 100])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', [])->assertUnprocessable();

    orderApi($world)->getJson('/api/v1/invoicing/customer-credit/'.$world['customer']->id)->assertOk()->assertJsonPath('credit_limit', 15000);
    orderApi($world)->getJson('/api/v1/invoicing/customer-credit/'.$other['customer']->id)->assertNotFound();
});

test('a role without credit management cannot change the limit', function (): void {
    $world = orderWorld('member');

    orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $world['customer']->id, 'credit_limit' => 1000])->assertForbidden();
});

test('an order above the credit limit goes on hold, the exposure shows it, and releasing the hold confirms the order', function (): void {
    $world = orderWorld();
    orderApi($world)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $world['customer']->id, 'credit_limit' => 15000])->assertOk();

    // 2 x 10,000 + 22% = 24,400, over the limit of 15,000.
    $order = orderApi($world)->postJson('/api/v1/invoicing/orders', orderPayload($world, ['auto_submit' => true]))->assertCreated();

    expect($order->json('status'))->toBe('on_hold')
        ->and($order->json('credit_status'))->toBe('on_hold')
        ->and($order->json('credit_hold_reason'))->toContain('credit limit')
        ->and($order->json('holds'))->toHaveCount(1);

    $hold = SalesOrderHold::query()->where('sales_order_id', $order->json('id'))->where('status', 'active')->sole();
    expect($hold->hold_type)->toBe('credit_limit');

    $profile = orderApi($world)->getJson('/api/v1/invoicing/customer-credit/'.$world['customer']->id)->assertOk();
    expect($profile->json('credit_limit'))->toEqual(15000)
        ->and($profile->json('total_exposure'))->toBeGreaterThanOrEqual(24400.0);

    $released = orderApi($world)->postJson("/api/v1/invoicing/customer-credit/holds/{$hold->id}/release", ['notes' => 'Approved by the CFO'])->assertOk();
    expect($released->json('message'))->toBe('Hold released successfully')
        ->and($released->json('hold_id'))->toBe((string) $hold->id)
        ->and($released->json('status'))->toBe('released')
        ->and(SalesOrder::query()->findOrFail($order->json('id'))->status)->toBe('confirmed')
        ->and(SalesOrderHold::query()->findOrFail($hold->id)->release_notes)->toBe('Approved by the CFO');
});

test('a hold of another tenant cannot be released and the notes are bounded', function (): void {
    $world = orderWorld();
    $other = orderWorld();
    orderApi($other)->postJson('/api/v1/invoicing/customer-credit/limit', ['customer_id' => (string) $other['customer']->id, 'credit_limit' => 1000])->assertOk();
    $order = orderApi($other)->postJson('/api/v1/invoicing/orders', orderPayload($other, ['auto_submit' => true]))->assertCreated();
    $hold = SalesOrderHold::withoutGlobalScopes()->where('sales_order_id', $order->json('id'))->sole();

    orderApi($world)->postJson("/api/v1/invoicing/customer-credit/holds/{$hold->id}/release", [])->assertNotFound();
    orderApi($other)->postJson("/api/v1/invoicing/customer-credit/holds/{$hold->id}/release", ['notes' => str_repeat('x', 2001)])->assertUnprocessable()->assertJsonValidationErrors('notes');

    expect(SalesOrderHold::withoutGlobalScopes()->findOrFail($hold->id)->status)->toBe('active');
});
