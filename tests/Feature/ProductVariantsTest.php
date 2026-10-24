<?php

declare(strict_types=1);

use App\Events\Inventory\ProductVariantDeactivated;
use App\Jobs\PushStockToEcommerceJob;
use App\Models\Contact;
use App\Models\EcommerceChannel;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'FatturaFacile Italy SRL', 'legal_name' => 'FatturaFacile Italy SRL', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->otherTenant = Tenant::create(['name' => 'Other SRL', 'legal_name' => 'Other SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $this->user = User::create(['name' => 'Leonardo', 'email' => 'leo@fatturafacile.com', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->user);

    Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);

    $this->parent = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'TSH', 'name' => 'T-shirt', 'sale_price' => 20, 'cost_price' => 8, 'tax_rate' => 22,
        'track_inventory' => true, 'unit_measure' => 'pcs',
    ]);
    $this->foreignParent = Product::create(['tenant_id' => $this->otherTenant->id, 'sku' => 'TSH', 'name' => 'Foreign T-shirt', 'sale_price' => 5, 'tax_rate' => 0]);
});

function pvApi(?string $tenantId = null)
{
    return test()->withHeader('X-Tenant-ID', $tenantId ?? test()->tenant->id);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function pvVariant(array $attributes = ['Size' => 'M'], array $overrides = []): Product
{
    return Product::create(array_merge([
        'tenant_id' => test()->tenant->id, 'sku' => 'TSH-'.Str::upper(Str::random(6)), 'name' => 'T-shirt variant', 'sale_price' => 20, 'tax_rate' => 22,
        'track_inventory' => true, 'parent_id' => test()->parent->id, 'is_variant' => true, 'attributes' => $attributes + [Product::DISCONTINUE_FLAG => false],
    ], $overrides));
}

function pvStock(Product $product, float $quantity): void
{
    pvApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $product->id, 'quantity' => $quantity])->assertCreated();
}

function pvRecordOut(Product $product, float $quantity): void
{
    app(TenantContext::class)->runWithTenant(test()->tenant, fn () => app(StockService::class)->record($product->id, 'out', $quantity));
}

// ---------------------------------------------------------------------------------------------------------------
// Matrix generation
// ---------------------------------------------------------------------------------------------------------------

test('a matrix generates the cartesian product with deterministic skus, names and inherited data', function (): void {
    $response = pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants/generate", [
        'attributes' => ['size' => ['S', 'M', 'L'], 'color' => ['Red', 'Blue']],
    ])->assertCreated();

    expect($response->json('data.created_count'))->toBe(6)->and($response->json('data.skipped_count'))->toBe(0);

    $variants = Product::variantsOnly()->orderBy('sku')->get();
    expect($variants)->toHaveCount(6)
        ->and($variants->pluck('sku')->all())->toBe(['TSH-L-BLUE', 'TSH-L-RED', 'TSH-M-BLUE', 'TSH-M-RED', 'TSH-S-BLUE', 'TSH-S-RED'])
        ->and($variants->pluck('parent_id')->unique()->all())->toBe([$this->parent->id])
        ->and($variants->every(fn (Product $variant): bool => $variant->is_variant && $variant->is_active && $variant->track_inventory))->toBeTrue();

    $red = $variants->firstWhere('sku', 'TSH-M-RED');
    expect($red->name)->toBe('T-shirt (size: M, color: Red)')
        ->and((float) $red->sale_price)->toBe(20.0)
        ->and((float) $red->cost_price)->toBe(8.0)
        ->and((float) $red->tax_rate)->toBe(22.0)
        ->and($red->optionAttributes())->toBe(['size' => 'M', 'color' => 'Red'])
        ->and($red->discontinuesOnZeroStock())->toBeFalse();
});

test('generating again skips existing combinations and only adds the new ones', function (): void {
    pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants/generate", ['attributes' => ['size' => ['S', 'M']]])->assertCreated();

    $response = pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants/generate", ['attributes' => ['size' => ['s', 'M', 'L']]])->assertCreated();

    expect($response->json('data.created_count'))->toBe(1)
        ->and($response->json('data.skipped_count'))->toBe(2)
        ->and($response->json('data.created.0.sku'))->toBe('TSH-L')
        ->and(Product::variantsOnly()->count())->toBe(3);
});

test('price modifiers, sequential skus and barcodes are applied to generated variants', function (): void {
    pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants/generate", [
        'attributes' => ['size' => ['S', 'XL']], 'price_modifier' => ['type' => 'percent', 'value' => 10],
        'generate_barcode' => true, 'discontinue_on_zero_stock' => true,
    ])->assertCreated();

    $variants = Product::variantsOnly()->orderBy('sku')->get();
    expect($variants->map(fn (Product $variant): float => (float) $variant->sale_price)->all())->toBe([22.0, 22.0])
        ->and($variants->every(fn (Product $variant): bool => $variant->discontinuesOnZeroStock()))->toBeTrue()
        ->and($variants->every(fn (Product $variant): bool => preg_match('/^2\d{12}$/', (string) $variant->barcode) === 1))->toBeTrue()
        ->and($variants->pluck('barcode')->unique())->toHaveCount(2);

    pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants/generate", [
        'attributes' => ['color' => ['Red']], 'price_modifier' => ['type' => 'fixed', 'value' => -5.5],
        'auto_generate_sku' => false, 'generate_barcode' => true, 'barcode_type' => 'code128',
    ])->assertCreated()->assertJsonPath('data.created.0.sku', 'TSH-003')->assertJsonPath('data.created.0.price', 14.5)->assertJsonPath('data.created.0.barcode', 'TSH-003');
});

test('skus are normalised and kept unique when values collapse to the same text', function (): void {
    $response = pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants/generate", [
        'attributes' => ['size' => ['Extra Large/XL', 'Extra-Large XL', 'à-la-carte']],
    ])->assertCreated();

    expect($response->json('data.created.*.sku'))->toBe(['TSH-EXTRALARGEXL', 'TSH-EXTRALARGEXL-2', 'TSH-ALACARTE']);
});

test('matrix validation rejects bad attributes, modifiers and oversized matrices', function (): void {
    $url = "/api/v1/inventory/products/{$this->parent->id}/variants/generate";

    pvApi()->postJson($url, [])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => 'S']])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => []]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => ['S', 's']]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => ['']]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['a' => ['1'], 'b' => ['1'], 'c' => ['1'], 'd' => ['1'], 'e' => ['1'], 'f' => ['1']]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => ['S'], 'price_modifier' => []], 'price_modifier' => ['type' => 'weird', 'value' => 1]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => ['S']], 'price_modifier' => ['type' => 'percent', 'value' => -150]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => ['S']], 'price_modifier' => ['type' => 'fixed', 'value' => -50]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['size' => ['S'], 'barcode_type' => 'qrcode'], 'barcode_type' => 'qrcode'])->assertUnprocessable();

    $values = fn (string $prefix): array => array_map(fn (int $i): string => "{$prefix}{$i}", range(1, 20));
    pvApi()->postJson($url, ['attributes' => ['a' => $values('a'), 'b' => $values('b')]])->assertUnprocessable();

    expect(Product::variantsOnly()->count())->toBe(0);
});

test('a variant cannot be the parent of other variants', function (): void {
    $variant = pvVariant();

    pvApi()->postJson("/api/v1/inventory/products/{$variant->id}/variants/generate", ['attributes' => ['size' => ['S']]])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------
// Single variants
// ---------------------------------------------------------------------------------------------------------------

test('a variant is created by hand, listed with its stock, shown and updated with the discontinue flag', function (): void {
    $created = pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants", [
        'sku' => 'TSH-CUSTOM', 'attributes' => ['Size' => 'M', 'Color' => 'Green'], 'price' => 24.5, 'barcode' => '1234567890123', 'discontinue_on_zero_stock' => true,
    ])->assertCreated();

    $id = $created->json('data.id');
    expect($created->json('data.attributes'))->toBe(['Size' => 'M', 'Color' => 'Green'])
        ->and($created->json('data.discontinue_on_zero_stock'))->toBeTrue()
        ->and($created->json('data.price'))->toEqual(24.5);

    pvStock(Product::find($id), 7);

    $list = pvApi()->getJson("/api/v1/inventory/products/{$this->parent->id}/variants")->assertOk();
    expect($list->json('data'))->toHaveCount(1)->and($list->json('data.0.stock'))->toEqual(7);

    pvApi()->getJson("/api/v1/inventory/products/variants/{$id}")->assertOk()->assertJsonPath('data.stock', 7)->assertJsonPath('data.sku', 'TSH-CUSTOM');

    $updated = pvApi()->putJson("/api/v1/inventory/products/variants/{$id}", [
        'sku' => 'TSH-RENAMED', 'price' => 30, 'barcode' => null, 'attributes' => ['Size' => 'L', 'Color' => 'Green'], 'discontinue_on_zero_stock' => false, 'is_active' => false,
    ])->assertOk();

    expect($updated->json('data.sku'))->toBe('TSH-RENAMED')
        ->and($updated->json('data.price'))->toEqual(30)
        ->and($updated->json('data.barcode'))->toBeNull()
        ->and($updated->json('data.discontinue_on_zero_stock'))->toBeFalse()
        ->and($updated->json('data.is_active'))->toBeFalse()
        ->and($updated->json('data.name'))->toBe('T-shirt (Size: L, Color: Green)');

    // Only the flag changes: the attributes stay as they are.
    pvApi()->putJson("/api/v1/inventory/products/variants/{$id}", ['discontinue_on_zero_stock' => true])->assertOk()
        ->assertJsonPath('data.attributes', ['Size' => 'L', 'Color' => 'Green'])->assertJsonPath('data.discontinue_on_zero_stock', true);
});

test('the discontinue flag can also travel inside the attributes and defaults to false', function (): void {
    $withFlag = pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants", ['attributes' => ['Size' => 'S', 'discontinue_on_zero_stock' => true]])->assertCreated();
    $without = pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants", ['attributes' => ['Size' => 'M']])->assertCreated();

    expect($withFlag->json('data.discontinue_on_zero_stock'))->toBeTrue()
        ->and($withFlag->json('data.attributes'))->toBe(['Size' => 'S'])
        ->and($withFlag->json('data.sku'))->toBe('TSH-S')
        ->and($without->json('data.discontinue_on_zero_stock'))->toBeFalse()
        ->and($without->json('data.price'))->toEqual(20);
});

test('variant validation covers duplicates, skus, prices and attributes', function (): void {
    $url = "/api/v1/inventory/products/{$this->parent->id}/variants";
    $existing = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    Product::create(['tenant_id' => $this->otherTenant->id, 'sku' => 'TSH-FREE', 'name' => 'Other', 'sale_price' => 1, 'tax_rate' => 0]);

    pvApi()->postJson($url, ['attributes' => ['size' => ' m ']])->assertUnprocessable();
    pvApi()->postJson($url, ['sku' => 'TSH-M', 'attributes' => ['Size' => 'XL']])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => []])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['discontinue_on_zero_stock' => true]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['Size' => ['nested']]])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['Size' => 'XL'], 'price' => -1])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['Size' => 'XL'], 'discontinue_on_zero_stock' => 'maybe'])->assertUnprocessable();
    pvApi()->postJson($url, ['attributes' => ['a' => '1', 'b' => '1', 'c' => '1', 'd' => '1', 'e' => '1', 'f' => '1']])->assertUnprocessable();

    // The same SKU of another tenant does not clash.
    pvApi()->postJson($url, ['sku' => 'TSH-FREE', 'attributes' => ['Size' => 'XL']])->assertCreated();

    $other = pvVariant(['Size' => 'S'], ['sku' => 'TSH-S']);
    pvApi()->putJson("/api/v1/inventory/products/variants/{$other->id}", ['attributes' => ['Size' => 'M']])->assertUnprocessable();
    pvApi()->putJson("/api/v1/inventory/products/variants/{$other->id}", ['sku' => $existing->sku])->assertUnprocessable();
    pvApi()->putJson("/api/v1/inventory/products/variants/{$other->id}", ['sku' => 'TSH-S', 'attributes' => ['Size' => 'S']])->assertOk();
});

// ---------------------------------------------------------------------------------------------------------------
// Auto-deactivation
// ---------------------------------------------------------------------------------------------------------------

test('a variant with the discontinue flag is deactivated when an outbound movement empties it', function (): void {
    Event::fake([ProductVariantDeactivated::class]);

    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    $variant->update(['attributes' => ['Size' => 'M', Product::DISCONTINUE_FLAG => true]]);
    pvStock($variant, 5);

    pvRecordOut($variant, 3);
    expect($variant->refresh()->is_active)->toBeTrue();
    Event::assertNotDispatched(ProductVariantDeactivated::class);

    pvRecordOut($variant, 2);
    expect($variant->refresh()->is_active)->toBeFalse();
    Event::assertDispatched(ProductVariantDeactivated::class, fn (ProductVariantDeactivated $event): bool => $event->product->id === $variant->id);
});

test('without the flag or the tenant preference a variant stays active at zero and negative stock', function (): void {
    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    pvStock($variant, 2);

    pvRecordOut($variant, 2);
    expect($variant->refresh()->is_active)->toBeTrue();

    pvRecordOut($variant, 3);
    expect($variant->refresh()->is_active)->toBeTrue()
        ->and(app(TenantContext::class)->runWithTenant($this->tenant, fn (): float => app(StockService::class)->quantityOf($variant->id)))->toBe(-3.0);
});

test('the tenant preference deactivates any product at zero stock, also through negative adjustments', function (): void {
    pvApi()->putJson('/api/v1/settings/preferences/inventory', ['auto_deactivate_on_zero_stock' => true])->assertOk();

    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    pvStock($variant, 4);

    pvApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $variant->id, 'quantity' => -4])->assertCreated();

    expect($variant->refresh()->is_active)->toBeFalse();

    // An inbound movement never deactivates.
    $other = pvVariant(['Size' => 'S'], ['sku' => 'TSH-S']);
    pvStock($other, 1);
    expect($other->refresh()->is_active)->toBeTrue();
});

test('the deactivation is propagated to e-commerce channels unless the tenant disables it', function (): void {
    Event::fake([ProductVariantDeactivated::class]);
    pvApi()->putJson('/api/v1/settings/preferences/inventory', ['auto_deactivate_on_zero_stock' => true, 'sync_deactivation_to_ecommerce' => false])->assertOk();

    $first = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    pvStock($first, 1);
    pvRecordOut($first, 1);

    expect($first->refresh()->is_active)->toBeFalse();
    Event::assertNotDispatched(ProductVariantDeactivated::class);

    pvApi()->putJson('/api/v1/settings/preferences/inventory', ['sync_deactivation_to_ecommerce' => null])->assertOk();

    $second = pvVariant(['Size' => 'L'], ['sku' => 'TSH-L']);
    pvStock($second, 1);
    pvRecordOut($second, 1);

    Event::assertDispatched(ProductVariantDeactivated::class, 1);
});

test('the deactivation listener queues a stock push to channels that synchronise stock', function (): void {
    pvApi()->putJson('/api/v1/settings/preferences/inventory', ['auto_deactivate_on_zero_stock' => true])->assertOk();

    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    pvStock($variant, 1);

    EcommerceChannel::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Shop', 'driver' => 'woocommerce', 'api_url' => 'https://woo.example.com',
        'credentials' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs'], 'webhook_secret' => 'secret-secret-secret', 'sync_stock_enabled' => true,
    ]);

    Queue::fake();
    pvRecordOut($variant, 1);

    expect($variant->refresh()->is_active)->toBeFalse();
    Queue::assertPushed(PushStockToEcommerceJob::class, fn (PushStockToEcommerceJob $job): bool => $job->productId === $variant->id);
});

test('preferences of the inventory group are validated and default to off', function (): void {
    expect(pvApi()->getJson('/api/v1/settings/preferences')->assertOk()->json('data.inventory'))->toBe([]);

    pvApi()->putJson('/api/v1/settings/preferences/inventory', ['auto_deactivate_on_zero_stock' => 'sometimes'])->assertUnprocessable();
    pvApi()->putJson('/api/v1/settings/preferences/inventory', ['auto_deactivate_on_zero_stock' => true, 'sync_deactivation_to_ecommerce' => false])->assertOk()
        ->assertJsonPath('data.auto_deactivate_on_zero_stock', true)->assertJsonPath('data.sync_deactivation_to_ecommerce', false);
});

test('inbound stock is refused for deactivated variants until they are reactivated', function (): void {
    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M', 'is_active' => false]);

    pvApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $variant->id, 'quantity' => 5])->assertUnprocessable();
    // Corrections downwards stay possible.
    pvApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $variant->id, 'quantity' => -1])->assertCreated();

    pvApi()->putJson("/api/v1/inventory/products/variants/{$variant->id}", ['is_active' => true])->assertOk();
    pvApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $variant->id, 'quantity' => 5])->assertCreated();

    // A deactivated simple product is not subject to the rule.
    $simple = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'SIMPLE', 'name' => 'Simple', 'sale_price' => 1, 'tax_rate' => 0, 'track_inventory' => true, 'is_active' => false]);
    pvApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $simple->id, 'quantity' => 5])->assertCreated();
});

// ---------------------------------------------------------------------------------------------------------------
// Deletion and isolation
// ---------------------------------------------------------------------------------------------------------------

test('an unreferenced variant is deleted for good while a referenced one is protected', function (): void {
    $free = pvVariant(['Size' => 'S'], ['sku' => 'TSH-S']);
    $used = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    pvStock($used, 3);

    pvApi()->deleteJson("/api/v1/inventory/products/variants/{$used->id}")->assertUnprocessable();
    expect(Product::find($used->id))->not->toBeNull();

    pvApi()->deleteJson("/api/v1/inventory/products/variants/{$free->id}")->assertOk();
    expect(Product::withTrashed()->find($free->id))->toBeNull();

    // The SKU of a deleted variant can be used again.
    pvApi()->postJson("/api/v1/inventory/products/{$this->parent->id}/variants", ['sku' => 'TSH-S', 'attributes' => ['Size' => 'S']])->assertCreated();
});

test('a variant referenced by a document line cannot be deleted', function (): void {
    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M', 'track_inventory' => false]);
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'name' => 'Mario Rossi', 'email' => 'mario@example.com', 'type' => 'customer', 'country' => 'IT']);

    pvApi()->postJson('/api/v1/sales/orders', [
        'contact_id' => $contact->id, 'date' => '2026-03-10', 'currency' => 'EUR',
        'items' => [['product_id' => $variant->id, 'description' => 'T-shirt M', 'quantity' => 1, 'unit_price' => 20, 'tax_rate' => 22]],
    ])->assertCreated();

    pvApi()->deleteJson("/api/v1/inventory/products/variants/{$variant->id}")->assertUnprocessable();
    expect(Product::find($variant->id))->not->toBeNull();
});

test('variants of other tenants can neither be seen nor changed', function (): void {
    $foreignVariant = Product::create([
        'tenant_id' => $this->otherTenant->id, 'sku' => 'TSH-F', 'name' => 'Foreign variant', 'sale_price' => 5, 'tax_rate' => 0,
        'parent_id' => $this->foreignParent->id, 'is_variant' => true, 'attributes' => ['Size' => 'M'],
    ]);
    $own = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);

    pvApi()->getJson("/api/v1/inventory/products/{$this->foreignParent->id}/variants")->assertNotFound();
    pvApi()->postJson("/api/v1/inventory/products/{$this->foreignParent->id}/variants", ['attributes' => ['Size' => 'S']])->assertNotFound();
    pvApi()->postJson("/api/v1/inventory/products/{$this->foreignParent->id}/variants/generate", ['attributes' => ['Size' => ['S']]])->assertNotFound();
    pvApi()->getJson("/api/v1/inventory/products/variants/{$foreignVariant->id}")->assertNotFound();
    pvApi()->putJson("/api/v1/inventory/products/variants/{$foreignVariant->id}", ['price' => 1])->assertNotFound();
    pvApi()->deleteJson("/api/v1/inventory/products/variants/{$foreignVariant->id}")->assertNotFound();

    $list = pvApi()->getJson("/api/v1/inventory/products/{$this->parent->id}/variants")->assertOk();
    expect($list->json('data.*.id'))->toBe([$own->id])
        ->and(Product::withoutGlobalScopes()->find($foreignVariant->id))->not->toBeNull();

    // The other tenant sees its own variant through its own context.
    pvApi($this->otherTenant->id)->getJson("/api/v1/inventory/products/variants/{$foreignVariant->id}")->assertOk();
    pvApi($this->otherTenant->id)->getJson("/api/v1/inventory/products/variants/{$own->id}")->assertNotFound();
});

test('lifecycle flags never leak into the attributes published to e-commerce', function (): void {
    $variant = pvVariant(['Size' => 'M'], ['sku' => 'TSH-M']);
    $variant->update(['attributes' => ['Size' => 'M', Product::DISCONTINUE_FLAG => true]]);

    expect($variant->refresh()->optionAttributes())->toBe(['Size' => 'M'])
        ->and($variant->discontinuesOnZeroStock())->toBeTrue()
        ->and($variant->attributes)->toBe(['Size' => 'M', Product::DISCONTINUE_FLAG => true]);
});
