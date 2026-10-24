<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'FatturaFacile Italy SRL',
        'legal_name' => 'FatturaFacile Italy SRL',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $user = User::create([
        'name' => 'Leonardo',
        'email' => 'leo@fatturafacile.com',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->product = Product::create([
        'tenant_id' => $this->tenant->id,
        'sku' => 'WID-1',
        'name' => 'Widget',
        'sale_price' => 100,
        'tax_rate' => 22,
    ]);
    $this->contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Milan Customer SpA',
        'country' => 'IT',
    ]);
});

function catalogApi()
{
    return test()->withHeader('X-Tenant-ID', test()->tenant->id);
}

test('categories, brands and units support CRUD with per-tenant uniqueness', function (string $resource, array $payload, string $uniqueField): void {
    $created = catalogApi()->postJson("/api/v1/catalog/{$resource}", $payload)->assertCreated();
    $id = $created->json('data.id');

    catalogApi()->postJson("/api/v1/catalog/{$resource}", $payload)
        ->assertUnprocessable()->assertJsonValidationErrors($uniqueField);

    catalogApi()->putJson("/api/v1/catalog/{$resource}/{$id}", $payload)->assertOk();
    catalogApi()->getJson("/api/v1/catalog/{$resource}")->assertOk()->assertJsonPath('meta.total', 1);
    catalogApi()->deleteJson("/api/v1/catalog/{$resource}/{$id}")->assertOk();
    catalogApi()->getJson("/api/v1/catalog/{$resource}/{$id}")->assertNotFound();
})->with([
    'categories' => ['categories', ['name' => 'Hardware'], 'name'],
    'brands' => ['brands', ['name' => 'Acme'], 'name'],
    'units' => ['units', ['code' => 'KG', 'name' => 'Kilogram'], 'code'],
]);

test('price list resolution prefers contact list, quantity breaks, default list, then product price', function (): void {
    $vip = catalogApi()->postJson('/api/v1/catalog/price-lists', [
        'name' => 'VIP', 'currency' => 'EUR',
        'items' => [
            ['product_id' => $this->product->id, 'price' => 80],
            ['product_id' => $this->product->id, 'price' => 70, 'min_quantity' => 10],
        ],
    ])->assertCreated();

    $default = catalogApi()->postJson('/api/v1/catalog/price-lists', [
        'name' => 'Base price list', 'currency' => 'EUR', 'is_default' => true,
        'items' => [['product_id' => $this->product->id, 'price' => 90]],
    ])->assertCreated();

    $resolve = fn (array $query) => catalogApi()->getJson('/api/v1/catalog/price-lists/resolve-price?'.http_build_query(
        array_merge(['product_id' => $this->product->id], $query)
    ));

    // No contact: default list.
    expect($resolve([])->json('data.price'))->toEqual(90.0);

    // Contact on VIP list: base price and quantity break.
    $this->contact->update(['price_list_id' => $vip->json('data.id')]);
    expect($resolve(['contact_id' => $this->contact->id, 'quantity' => 1])->json('data.price'))->toEqual(80.0)
        ->and($resolve(['contact_id' => $this->contact->id, 'quantity' => 12])->json('data.price'))->toEqual(70.0);

    // Expired lists are ignored; falls back to the product sale price.
    catalogApi()->putJson('/api/v1/catalog/price-lists/'.$vip->json('data.id'), ['valid_to' => '2020-01-01'])->assertOk();
    catalogApi()->putJson('/api/v1/catalog/price-lists/'.$default->json('data.id'), ['is_active' => false])->assertOk();
    $fallback = $resolve(['contact_id' => $this->contact->id]);
    expect($fallback->json('data.price'))->toEqual(100.0)->and($fallback->json('data.source'))->toBe('product');
});

test('only one default price list is kept per tenant', function (): void {
    $first = catalogApi()->postJson('/api/v1/catalog/price-lists', ['name' => 'A', 'currency' => 'EUR', 'is_default' => true])->json('data.id');
    catalogApi()->postJson('/api/v1/catalog/price-lists', ['name' => 'B', 'currency' => 'EUR', 'is_default' => true])->assertCreated();

    expect(catalogApi()->getJson("/api/v1/catalog/price-lists/{$first}")->json('data.is_default'))->toBeFalse();
});

test('payment term builds installment schedule that sums to the amount', function (): void {
    $term = catalogApi()->postJson('/api/v1/catalog/payment-terms', [
        'code' => '30-60-90', 'name' => '30/60/90 FM', 'end_of_month' => true, 'sdi_terms_code' => 'TP01',
        'installments' => [
            ['percent' => 33.33, 'days' => 30],
            ['percent' => 33.33, 'days' => 60],
            ['percent' => 33.34, 'days' => 90],
        ],
    ])->assertCreated();

    $schedule = catalogApi()->getJson('/api/v1/catalog/payment-terms/'.$term->json('data.id').'/schedule?amount=1000&date=2026-01-15')
        ->assertOk()->json('data.installments');

    expect(array_column($schedule, 'due_date'))->toBe(['2026-02-28', '2026-03-31', '2026-04-30'])
        ->and(round(array_sum(array_column($schedule, 'amount')), 2))->toBe(1000.0);
});

test('payment term rejects installments not summing to 100 and supports early discount', function (): void {
    catalogApi()->postJson('/api/v1/catalog/payment-terms', [
        'code' => 'BAD', 'name' => 'Bad',
        'installments' => [['percent' => 50, 'days' => 30], ['percent' => 40, 'days' => 60]],
    ])->assertUnprocessable()->assertJsonValidationErrors('installments');

    $term = catalogApi()->postJson('/api/v1/catalog/payment-terms', [
        'code' => 'RB30', 'name' => 'Bank transfer 30 days', 'days' => 30, 'discount_percent' => 2, 'discount_days' => 10,
    ])->assertCreated();

    $data = catalogApi()->getJson('/api/v1/catalog/payment-terms/'.$term->json('data.id').'/schedule?amount=500&date=2026-01-01')->json('data');
    expect($data['installments'][0]['due_date'])->toBe('2026-01-31')
        ->and($data['early_discount'])->toEqual(['until' => '2026-01-11', 'percent' => 2.0, 'amount' => 10.0]);
});
