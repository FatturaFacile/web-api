<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/ProductCatalogSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    pcSetUp($this);
});

/**
 * @param  list<array{component_id: string, quantity: string|int, is_optional?: bool}>  $components
 */
function pcSetKit(object $test, string $kitId, array $components): TestResponse
{
    return $test->putJson('/api/v1/catalog/products/'.$kitId.'/kit-components', ['components' => $components]);
}

it('saves the bill of materials of a phantom kit and shows it in the detail', function (): void {
    $bolt = pcProduct($this, ['sku' => 'BOLT']);
    $nut = pcProduct($this, ['sku' => 'NUT']);
    $kit = pcProduct($this, ['sku' => 'KIT', 'kit_type' => 'phantom']);

    $detail = pcSetKit($this, $kit['id'], [
        ['component_id' => $bolt['id'], 'quantity' => '4'],
        ['component_id' => $nut['id'], 'quantity' => '4.5', 'is_optional' => true],
    ])->assertOk()->json('data');

    expect($detail['kit_components'])->toHaveCount(2)
        ->and($detail['kit_components'][0]['sku'])->toBe('BOLT')
        ->and($detail['kit_components'][1]['is_optional'])->toBeTrue()
        ->and($detail['kit_components'][1]['quantity'])->toBe('4.500000');
});

it('replaces the bill of materials every time it is saved', function (): void {
    $bolt = pcProduct($this);
    $nut = pcProduct($this);
    $kit = pcProduct($this, ['kit_type' => 'phantom']);

    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => 1]])->assertOk();
    $detail = pcSetKit($this, $kit['id'], [['component_id' => $nut['id'], 'quantity' => 2]])->assertOk()->json('data');

    expect($detail['kit_components'])->toHaveCount(1)->and($detail['kit_components'][0]['component_id'])->toBe($nut['id']);
});

it('refuses components on a product that is not a kit', function (): void {
    $bolt = pcProduct($this);
    $plain = pcProduct($this);

    pcSetKit($this, $plain['id'], [['component_id' => $bolt['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'not_a_kit');
});

it('refuses a kit that contains itself, a repeated component and a quantity of zero', function (): void {
    $bolt = pcProduct($this);
    $kit = pcProduct($this, ['kit_type' => 'phantom']);

    pcSetKit($this, $kit['id'], [['component_id' => $kit['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'kit_contains_itself');
    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => 1], ['component_id' => $bolt['id'], 'quantity' => 2]])->assertStatus(422);
    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => 0]])->assertStatus(422);
});

it('refuses a digital good and an inactive product as a component', function (): void {
    $digital = pcProduct($this, ['product_type' => 'digital']);
    $off = pcProduct($this, ['is_active' => false]);
    $kit = pcProduct($this, ['kit_type' => 'phantom']);

    pcSetKit($this, $kit['id'], [['component_id' => $digital['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'component_type_not_allowed');
    pcSetKit($this, $kit['id'], [['component_id' => $off['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'component_inactive');
});

it('refuses a circular kit dependency, direct or through nested kits', function (): void {
    $a = pcProduct($this, ['sku' => 'KIT-A', 'kit_type' => 'phantom']);
    $b = pcProduct($this, ['sku' => 'KIT-B', 'kit_type' => 'phantom']);
    $c = pcProduct($this, ['sku' => 'KIT-C', 'kit_type' => 'phantom']);
    $leaf = pcProduct($this, ['sku' => 'LEAF']);

    pcSetKit($this, $a['id'], [['component_id' => $b['id'], 'quantity' => 1], ['component_id' => $leaf['id'], 'quantity' => 1]])->assertOk();
    pcSetKit($this, $b['id'], [['component_id' => $c['id'], 'quantity' => 1]])->assertOk();

    pcSetKit($this, $c['id'], [['component_id' => $a['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'kit_cycle');
    pcSetKit($this, $b['id'], [['component_id' => $a['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'kit_cycle');
});

it('refuses a physical kit that does not track stock', function (): void {
    $bolt = pcProduct($this);
    $kit = pcProduct($this, ['kit_type' => 'physical']);
    Product::query()->whereKey($kit['id'])->update(['track_inventory' => false]);

    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => 1]])->assertStatus(422)->assertJsonPath('code', 'physical_kit_must_track_stock');
});

it('explodes a phantom kit into its components with exact quantities', function (): void {
    $bolt = pcProduct($this, ['sku' => 'BOLT']);
    $nut = pcProduct($this, ['sku' => 'NUT']);
    $kit = pcProduct($this, ['sku' => 'KIT', 'kit_type' => 'phantom']);
    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => '4'], ['component_id' => $nut['id'], 'quantity' => '0.5']])->assertOk();

    $lines = $this->getJson('/api/v1/catalog/products/'.$kit['id'].'/explosion?quantity=3')->assertOk()->json('data');

    expect($lines)->toHaveCount(2)
        ->and($lines[0]['sku'])->toBe('BOLT')->and($lines[0]['quantity'])->toBe('12')->and($lines[0]['via_kits'])->toBe(['KIT'])
        ->and($lines[1]['sku'])->toBe('NUT')->and($lines[1]['quantity'])->toBe('1.5');
});

it('leaves optional components out unless they are asked for', function (): void {
    $bolt = pcProduct($this, ['sku' => 'BOLT']);
    $gift = pcProduct($this, ['sku' => 'GIFT']);
    $kit = pcProduct($this, ['kit_type' => 'phantom']);
    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => 1], ['component_id' => $gift['id'], 'quantity' => 1, 'is_optional' => true]])->assertOk();

    expect($this->getJson('/api/v1/catalog/products/'.$kit['id'].'/explosion')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/v1/catalog/products/'.$kit['id'].'/explosion?include_optional=1')->json('data'))->toHaveCount(2);
});

it('explodes nested phantom kits down to the leaves and adds up repeated components', function (): void {
    $bolt = pcProduct($this, ['sku' => 'BOLT']);
    $inner = pcProduct($this, ['sku' => 'INNER', 'kit_type' => 'phantom']);
    $outer = pcProduct($this, ['sku' => 'OUTER', 'kit_type' => 'phantom']);
    pcSetKit($this, $inner['id'], [['component_id' => $bolt['id'], 'quantity' => 2]])->assertOk();
    pcSetKit($this, $outer['id'], [['component_id' => $inner['id'], 'quantity' => 3], ['component_id' => $bolt['id'], 'quantity' => 1]])->assertOk();

    $lines = $this->getJson('/api/v1/catalog/products/'.$outer['id'].'/explosion?quantity=2')->assertOk()->json('data');

    expect($lines)->toHaveCount(1)->and($lines[0]['sku'])->toBe('BOLT')->and($lines[0]['quantity'])->toBe('14')
        ->and($lines[0]['via_kits'])->toContain('INNER', 'OUTER');
});

it('never explodes a physical kit: it is stocked under its own SKU', function (): void {
    $bolt = pcProduct($this, ['sku' => 'BOLT']);
    $box = pcProduct($this, ['sku' => 'BOX', 'kit_type' => 'physical']);
    pcSetKit($this, $box['id'], [['component_id' => $bolt['id'], 'quantity' => 5]])->assertOk();

    $lines = $this->getJson('/api/v1/catalog/products/'.$box['id'].'/explosion?quantity=2')->assertOk()->json('data');

    expect($lines)->toHaveCount(1)->and($lines[0]['sku'])->toBe('BOX')->and($lines[0]['quantity'])->toBe('2');
});

it('refuses to explode a phantom kit without components', function (): void {
    $kit = pcProduct($this, ['kit_type' => 'phantom']);

    $this->getJson('/api/v1/catalog/products/'.$kit['id'].'/explosion')->assertStatus(422)->assertJsonPath('code', 'phantom_kit_empty');
});

it('splits commercial lines from picking and delivery note lines', function (): void {
    $bolt = pcProduct($this, ['sku' => 'BOLT']);
    $nut = pcProduct($this, ['sku' => 'NUT']);
    $kit = pcProduct($this, ['sku' => 'KIT', 'kit_type' => 'phantom']);
    $box = pcProduct($this, ['sku' => 'BOX', 'kit_type' => 'physical']);
    pcSetKit($this, $kit['id'], [['component_id' => $bolt['id'], 'quantity' => 4], ['component_id' => $nut['id'], 'quantity' => 4]])->assertOk();
    pcSetKit($this, $box['id'], [['component_id' => $nut['id'], 'quantity' => 1]])->assertOk();

    $result = $this->postJson('/api/v1/catalog/kits/explode', ['lines' => [
        ['product_id' => $kit['id'], 'quantity' => 2],
        ['product_id' => $box['id'], 'quantity' => 1],
        ['product_id' => $bolt['id'], 'quantity' => 3],
    ]])->assertOk()->json('data');

    expect(array_column($result['commercial'], 'sku'))->toBe(['KIT', 'BOX', 'BOLT'])
        ->and($result['commercial'][0]['kit_type'])->toBe('phantom')
        ->and($result['commercial'][1]['kit_type'])->toBe('physical');

    $warehouse = collect($result['warehouse'])->keyBy('sku');

    expect($warehouse->keys()->sort()->values()->all())->toBe(['BOLT', 'BOX', 'NUT'])
        ->and($warehouse['BOLT']['quantity'])->toBe('11')
        ->and($warehouse['NUT']['quantity'])->toBe('8')
        ->and($warehouse['BOX']['quantity'])->toBe('1');
});

it('refuses an explosion of lines of another company', function (): void {
    $other = pcOtherTenantProduct($this);

    $this->postJson('/api/v1/catalog/kits/explode', ['lines' => [['product_id' => $other->id, 'quantity' => 1]]])->assertStatus(422);
});

it('cannot turn a product with variants into a kit', function (): void {
    $attributes = pcStandardAttributes($this);
    $parent = pcProduct($this, ['sku' => 'TSH']);
    $this->postJson('/api/v1/catalog/products/'.$parent['id'].'/matrix/generate', pcMatrix($attributes, ['s'], ['black']))->assertCreated();

    $this->putJson('/api/v1/catalog/products/'.$parent['id'], ['kit_type' => 'phantom'])->assertStatus(422)->assertJsonPath('code', 'kit_cannot_have_variants');
});
