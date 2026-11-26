<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ProductCatalog\Domain\Support\Gtin;
use Modules\ProductCatalog\Models\CatalogAttribute;

require_once __DIR__.'/ProductCatalogSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    pcSetUp($this);
});

it('creates an attribute with values, SKU tokens and surcharges', function (): void {
    $attributes = pcStandardAttributes($this);

    expect($attributes['size']['values'])->toHaveCount(5)
        ->and(collect($attributes['size']['values'])->firstWhere('code', 'xl')['price_delta'])->toBe('2.0000')
        ->and(collect($attributes['size']['values'])->firstWhere('code', 'xl')['price_delta_type'])->toBe('fixed');

    $this->getJson('/api/v1/catalog/attributes')->assertOk()->assertJsonCount(2, 'data');
});

it('refuses a duplicated attribute code, a repeated SKU token and a bad token', function (): void {
    pcAttribute($this, 'size', [['code' => 's', 'label' => ['en' => 'S'], 'sku_token' => 'S']]);

    $this->postJson('/api/v1/catalog/attributes', ['code' => 'size', 'name' => ['en' => 'Size'], 'values' => []])->assertStatus(422)->assertJsonValidationErrors(['code']);
    $this->postJson('/api/v1/catalog/attributes', ['code' => 'fit', 'name' => ['en' => 'Fit'], 'values' => [
        ['code' => 'a', 'label' => ['en' => 'A'], 'sku_token' => 'X'],
        ['code' => 'b', 'label' => ['en' => 'B'], 'sku_token' => 'X'],
    ]])->assertStatus(422)->assertJsonPath('code', 'sku_token_duplicate');
    $this->postJson('/api/v1/catalog/attributes', ['code' => 'fit2', 'name' => ['en' => 'Fit'], 'values' => [
        ['code' => 'a', 'label' => ['en' => 'A'], 'sku_token' => 'bad token'],
    ]])->assertStatus(422)->assertJsonValidationErrors(['values.0.sku_token']);
});

it('needs an English name and an English label', function (): void {
    $this->postJson('/api/v1/catalog/attributes', ['code' => 'fit', 'name' => ['it' => 'Taglio'], 'values' => []])->assertStatus(422)->assertJsonValidationErrors(['name.en']);
});

it('previews the 5 x 3 matrix as 15 combinations with SKU, price and cost, and writes nothing', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20', 'cost_price' => '10']);

    $preview = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/preview', pcMatrix($attributes))->assertOk()->json('data');

    expect($preview['total'])->toBe(15)->and($preview['new'])->toBe(15)->and($preview['combinations'])->toHaveCount(15)
        ->and($preview['combinations'][0]['sku'])->toBe('TSH-S-BLK')
        ->and(Product::query()->where('is_variant', true)->count())->toBe(0);

    $xlNavy = collect($preview['combinations'])->firstWhere('sku', 'TSH-XL-NVY');
    expect($xlNavy['sale_price'])->toBe('22.0000')->and($xlNavy['cost_price'])->toBe('11.0000');
});

it('generates 15 variants with unique SKUs and valid in-house EAN-13 codes', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20', 'cost_price' => '10', 'tax_rate' => '22']);

    $response = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes) + ['generate_barcodes' => true])->assertCreated();
    $created = $response->json('data.created');

    expect($created)->toHaveCount(15)->and($response->json('data.skipped'))->toBe(0);

    $skus = array_column($created, 'sku');
    $barcodes = array_column($created, 'barcode');

    expect(array_unique($skus))->toHaveCount(15)->and(array_unique($barcodes))->toHaveCount(15);

    foreach ($barcodes as $barcode) {
        expect(Gtin::isValid($barcode))->toBeTrue();
    }

    $variant = collect($created)->firstWhere('sku', 'TSH-XXL-WHT');
    expect($variant['is_variant'])->toBeTrue()->and($variant['parent_id'])->toBe($parent['id'])
        ->and($variant['sale_price'])->toBe('23.5000')->and($variant['cost_price'])->toBe('10.0000')
        ->and($variant['attributes'])->toBe(['Size' => 'XXL', 'Color' => 'White'])
        ->and($variant['tax_rate'])->toBe('22.00');
});

it('records the axes and the values every variant carries', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s', 'm'], ['black']))->assertCreated();

    $detail = $this->getJson('/api/v1/catalog/products/'.$parent['id'])->assertOk()->json('data');

    expect($detail['axes'])->toHaveCount(2)->and($detail['variants'])->toHaveCount(2)
        ->and($detail['variants'][0]['value_ids'])->toHaveCount(2)
        ->and($detail['axes'][0]['attribute_id'])->toBe($attributes['size']['id']);
});

it('skips the combinations that already exist when the matrix grows', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $url = '/api/v1/catalog/products/'.$parent['id'].'/matrix/generate';

    $this->postJson($url, pcMatrix($attributes, ['s', 'm'], ['black']))->assertCreated()->assertJsonCount(2, 'data.created');
    $second = $this->postJson($url, pcMatrix($attributes, ['s', 'm', 'l'], ['black']))->assertCreated();

    expect($second->json('data.created'))->toHaveCount(1)->and($second->json('data.skipped'))->toBe(2)
        ->and(Product::query()->where('parent_id', $parent['id'])->count())->toBe(3);
});

it('avoids a SKU already used by another product', function (): void {
    $attributes = pcStandardAttributes($this);
    pcProduct($this, ['sku' => 'TSH-S-BLK']);
    $parent = pcProduct($this, ['sku' => 'TSH']);

    $created = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s'], ['black']))->assertCreated()->json('data.created');

    expect($created[0]['sku'])->toBe('TSH-S-BLK-2');
});

it('takes manual SKU, barcode and price overrides for a combination', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20']);
    $matrix = pcMatrix($attributes, ['s'], ['black']);
    $key = implode('|', [$matrix['axes'][0]['value_ids'][0], $matrix['axes'][1]['value_ids'][0]]);

    $created = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', $matrix + ['overrides' => [
        $key => ['sku' => 'CUSTOM-1', 'barcode' => '4006381333931', 'sale_price' => '31.5'],
    ]])->assertCreated()->json('data.created');

    expect($created[0]['sku'])->toBe('CUSTOM-1')->and($created[0]['barcode'])->toBe('4006381333931')
        ->and($created[0]['sale_price'])->toBe('31.5000')->and($created[0]['price_overridden'])->toBeTrue();
});

it('refuses a manual SKU that is taken and a barcode with a wrong check digit', function (): void {
    $attributes = pcStandardAttributes($this);
    pcProduct($this, ['sku' => 'TAKEN']);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $matrix = pcMatrix($attributes, ['s'], ['black']);
    $key = implode('|', [$matrix['axes'][0]['value_ids'][0], $matrix['axes'][1]['value_ids'][0]]);
    $url = '/api/v1/catalog/products/'.$parent['id'].'/matrix/generate';

    $this->postJson($url, $matrix + ['overrides' => [$key => ['sku' => 'taken']]])->assertStatus(422)->assertJsonPath('code', 'sku_taken');
    $this->postJson($url, $matrix + ['overrides' => [$key => ['barcode' => '4006381333932']]])->assertStatus(422)->assertJsonPath('code', 'gtin_invalid');
    expect(Product::query()->where('parent_id', $parent['id'])->count())->toBe(0);
});

it('refuses a matrix that is too large, empty or repeats an attribute', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $url = '/api/v1/catalog/products/'.$parent['id'].'/matrix/preview';

    $this->postJson($url, ['axes' => []])->assertStatus(422);
    $this->postJson($url, ['axes' => [
        ['attribute_id' => $attributes['size']['id'], 'value_ids' => pcValueIds($attributes['size'], ['s'])],
        ['attribute_id' => $attributes['size']['id'], 'value_ids' => pcValueIds($attributes['size'], ['m'])],
    ]])->assertStatus(422);

    $many = [];

    for ($i = 1; $i <= 24; $i++) {
        $many[] = ['code' => 'v'.$i, 'label' => ['en' => 'V'.$i], 'sku_token' => 'V'.$i];
    }

    $wide = pcAttribute($this, 'wide', $many);
    $tall = pcAttribute($this, 'tall', $many);
    $deep = pcAttribute($this, 'deep', $many);

    $this->postJson($url, ['axes' => [
        ['attribute_id' => $wide['id'], 'value_ids' => pcValueIds($wide, array_column($many, 'code'))],
        ['attribute_id' => $tall['id'], 'value_ids' => pcValueIds($tall, array_column($many, 'code'))],
        ['attribute_id' => $deep['id'], 'value_ids' => pcValueIds($deep, array_column($many, 'code'))],
    ]])->assertStatus(422)->assertJsonPath('code', 'too_many_combinations');
});

it('refuses a matrix on a variant and on a kit', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $variant = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s'], ['black']))->json('data.created.0');
    $kit = pcProduct($this, ['kit_type' => 'phantom']);

    $this->postJson('/api/v1/catalog/products/'.$variant['id'].'/matrix/generate', pcMatrix($attributes, ['m'], ['white']))->assertStatus(422)->assertJsonPath('code', 'variant_cannot_have_variants');
    $this->postJson('/api/v1/catalog/products/'.$kit['id'].'/matrix/generate', pcMatrix($attributes, ['m'], ['white']))->assertStatus(422)->assertJsonPath('code', 'kit_cannot_have_variants');
});

it('makes the variants follow the parent price and cost, except the ones with an override', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20', 'cost_price' => '10']);
    $created = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['m', 'xl'], ['black', 'navy']))->assertCreated()->json('data.created');
    $own = collect($created)->firstWhere('sku', 'TSH-M-BLK');

    $this->putJson('/api/v1/catalog/products/'.$own['id'], ['sale_price' => '50'])->assertOk()->assertJsonPath('data.price_overridden', true);
    $this->putJson('/api/v1/catalog/products/'.$parent['id'], ['sale_price' => '30', 'cost_price' => '12'])->assertOk();

    $detail = $this->getJson('/api/v1/catalog/products/'.$parent['id'])->json('data.variants');
    $bySku = collect($detail)->keyBy('sku');

    expect($bySku['TSH-M-BLK']['sale_price'])->toBe('50.0000')
        ->and($bySku['TSH-M-BLK']['cost_price'])->toBe('12.0000')
        ->and($bySku['TSH-XL-BLK']['sale_price'])->toBe('32.0000')
        ->and($bySku['TSH-XL-NVY']['cost_price'])->toBe('13.2000');
});

it('applies a surcharge of its own to a variant, fixed or percent of the parent', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20']);
    $variant = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['m'], ['black']))->json('data.created.0');

    $this->putJson('/api/v1/catalog/products/'.$variant['id'], ['price_delta' => '10', 'price_delta_type' => 'percent', 'price_overridden' => false])
        ->assertOk()->assertJsonPath('data.sale_price', '22.0000');
    $this->putJson('/api/v1/catalog/products/'.$variant['id'], ['price_delta' => '-3', 'price_delta_type' => 'fixed', 'price_overridden' => false])
        ->assertOk()->assertJsonPath('data.sale_price', '17.0000');
});

it('reprices the whole family when the surcharge of a value changes', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20']);
    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['xl'], ['black']))->assertCreated();

    $values = collect($attributes['size']['values'])->map(fn (array $value): array => $value['code'] === 'xl'
        ? ['code' => 'xl', 'label' => ['en' => 'XL'], 'sku_token' => 'XL', 'price_delta' => '5', 'price_delta_type' => 'fixed']
        : ['code' => $value['code'], 'label' => $value['label'], 'sku_token' => $value['sku_token'], 'price_delta' => $value['price_delta'], 'price_delta_type' => $value['price_delta_type']])->all();

    $this->putJson('/api/v1/catalog/attributes/'.$attributes['size']['id'], ['values' => $values])->assertOk();

    expect(Product::query()->where('sku', 'TSH-XL-BLK')->value('sale_price'))->toBe('25.0000');
});

it('refuses to remove a value that variants carry and an attribute a matrix uses', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s'], ['black']))->assertCreated();

    $this->putJson('/api/v1/catalog/attributes/'.$attributes['size']['id'], ['values' => [
        ['code' => 'm', 'label' => ['en' => 'M'], 'sku_token' => 'M'],
    ]])->assertStatus(409)->assertJsonPath('code', 'attribute_value_in_use');
    $this->deleteJson('/api/v1/catalog/attributes/'.$attributes['size']['id'])->assertStatus(409)->assertJsonPath('code', 'attribute_in_use');

    $unused = pcAttribute($this, 'fit', [['code' => 'slim', 'label' => ['en' => 'Slim'], 'sku_token' => 'SLM']]);
    $this->deleteJson('/api/v1/catalog/attributes/'.$unused['id'])->assertOk();
});

it('reprices on demand', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH', 'sale_price' => '20']);
    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s', 'm'], ['black']))->assertCreated();

    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/reprice')->assertOk()->assertJsonPath('data.repriced', 2);
});

it('keeps the attributes of one company away from another', function (): void {
    pcStandardAttributes($this);
    $other = pcInTenant($this->otherTenant, fn () => CatalogAttribute::create(['code' => 'size', 'name' => ['en' => 'Size'], 'sku_token' => 'SIZ']));

    $this->putJson('/api/v1/catalog/attributes/'.$other->id, ['name' => ['en' => 'Hacked']])->assertNotFound();
    $this->getJson('/api/v1/catalog/attributes')->assertOk()->assertJsonCount(2, 'data');
});

it('inherits the catalog data of the parent in every variant', function (): void {
    $attributes = pcStandardAttributes($this);
    $unit = pcUnit('PZ', 'Piece');
    $parent = pcProduct($this, [
        'sku' => 'TSH', 'unit_of_measure_id' => $unit, 'tares_code' => '61091000', 'taric_code' => '610910', 'country_of_origin' => 'PT',
        'net_weight_kg' => '0.2', 'gross_weight_kg' => '0.25',
    ]);
    $variant = $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s'], ['black']))->json('data.created.0');

    expect($variant['unit_of_measure_id'])->toBe($unit)->and($variant['tares_code'])->toBe('61091000')->and($variant['taric_code'])->toBe('610910')
        ->and($variant['country_of_origin'])->toBe('PT')->and($variant['net_weight_kg'])->toBe('0.200');
});
