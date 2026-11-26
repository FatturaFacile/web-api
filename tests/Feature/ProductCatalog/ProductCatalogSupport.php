<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\Tenant;
use App\Models\UnitOfMeasure;
use App\Models\VatCode;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;

require_once __DIR__.'/../Treasury/TreasurySupport.php';

/**
 * Two companies and a sales user signed in on the first one (sales write the catalog), plus the units of measure of the first company.
 */
function pcSetUp(object $test, string $role = 'sales'): void
{
    $suffix = Str::upper(Str::random(5));
    $test->tenant = Tenant::create(['name' => 'Catalogo '.$suffix, 'legal_name' => 'Catalogo '.$suffix.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Altro '.$suffix, 'legal_name' => 'Altro '.$suffix.' Srl', 'country' => 'CH', 'default_currency' => 'CHF']);
    $test->actor = treasuryActor($test, $role);
}

function pcUnit(string $code, string $name = ''): string
{
    return (string) UnitOfMeasure::query()->firstOrCreate(['code' => $code], ['name' => $name !== '' ? $name : $code])->id;
}

function pcVat(string $code, string $country, string $rate): string
{
    return (string) VatCode::query()->firstOrCreate(['code' => $code, 'country_iso' => $country], ['rate' => $rate, 'is_active' => true])->id;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pcPayload(array $overrides = []): array
{
    return array_merge([
        'sku' => 'SKU-'.Str::upper(Str::random(6)),
        'name' => 'Articolo di prova',
        'product_type' => 'storable',
        'cost_price' => '10.0000',
        'sale_price' => '25.0000',
        'tax_rate' => '22',
    ], $overrides);
}

/**
 * Creates a product through the API and returns its data.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pcProduct(object $test, array $overrides = []): array
{
    return $test->postJson('/api/v1/catalog/products', pcPayload($overrides))->assertCreated()->json('data');
}

/**
 * Creates an attribute with its values through the API.
 *
 * @param  list<array<string, mixed>>  $values
 * @return array<string, mixed>
 */
function pcAttribute(object $test, string $code, array $values, ?string $name = null): array
{
    return $test->postJson('/api/v1/catalog/attributes', [
        'code' => $code,
        'name' => ['en' => $name ?? ucfirst($code), 'it' => ucfirst($code)],
        'values' => $values,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $attribute
 * @param  list<string>  $codes
 * @return list<string> the ids of the values with these codes
 */
function pcValueIds(array $attribute, array $codes): array
{
    $byCode = collect($attribute['values'])->pluck('id', 'code');

    return array_values(array_map(static fn (string $code): string => (string) $byCode[$code], $codes));
}

/**
 * The size attribute (S, M, L, XL +2, XXL +3.5) and the colour attribute (black, white, navy).
 *
 * @return array{size: array<string, mixed>, color: array<string, mixed>}
 */
function pcStandardAttributes(object $test): array
{
    $size = pcAttribute($test, 'size', [
        ['code' => 's', 'label' => ['en' => 'S'], 'sku_token' => 'S'],
        ['code' => 'm', 'label' => ['en' => 'M'], 'sku_token' => 'M'],
        ['code' => 'l', 'label' => ['en' => 'L'], 'sku_token' => 'L'],
        ['code' => 'xl', 'label' => ['en' => 'XL'], 'sku_token' => 'XL', 'price_delta' => '2', 'price_delta_type' => 'fixed'],
        ['code' => 'xxl', 'label' => ['en' => 'XXL'], 'sku_token' => 'XXL', 'price_delta' => '3.5', 'price_delta_type' => 'fixed'],
    ]);
    $color = pcAttribute($test, 'color', [
        ['code' => 'black', 'label' => ['en' => 'Black'], 'sku_token' => 'BLK'],
        ['code' => 'white', 'label' => ['en' => 'White'], 'sku_token' => 'WHT'],
        ['code' => 'navy', 'label' => ['en' => 'Navy'], 'sku_token' => 'NVY', 'cost_delta' => '10', 'cost_delta_type' => 'percent'],
    ]);

    return ['size' => $size, 'color' => $color];
}

/**
 * Runs a callback as another company (records of the other company are invisible to the signed-in one).
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function pcInTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

function pcOtherTenantProduct(object $test, string $sku = 'OTHER-1'): Product
{
    return pcInTenant($test->otherTenant, fn (): Product => Product::create(['sku' => $sku, 'name' => 'Altrui', 'sale_price' => 1, 'cost_price' => 1, 'tax_rate' => 8.1]));
}

/**
 * @param  array{size: array<string, mixed>, color: array<string, mixed>}  $attributes
 * @param  list<string>  $sizes
 * @param  list<string>  $colors
 * @return array{axes: list<array{attribute_id: string, value_ids: list<string>}>}
 */
function pcMatrix(array $attributes, array $sizes = ['s', 'm', 'l', 'xl', 'xxl'], array $colors = ['black', 'white', 'navy']): array
{
    return ['axes' => [
        ['attribute_id' => $attributes['size']['id'], 'value_ids' => pcValueIds($attributes['size'], $sizes)],
        ['attribute_id' => $attributes['color']['id'], 'value_ids' => pcValueIds($attributes['color'], $colors)],
    ]];
}
