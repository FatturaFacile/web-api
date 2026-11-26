<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ProductCatalogSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    pcSetUp($this);
});

/**
 * A product with the hierarchy piece -> pack of 6 (sales) -> carton of 4 packs (purchase) -> pallet of 40 cartons.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pcPackedProduct(object $test, array $overrides = []): array
{
    $product = pcProduct($test, $overrides + ['sku' => 'TAPE', 'unit_of_measure_id' => pcUnit('PZ', 'Piece')]);

    $test->putJson('/api/v1/catalog/products/'.$product['id'].'/packagings', [
        'uom_precision' => $overrides['uom_precision'] ?? 4,
        'uom_rounding' => $overrides['uom_rounding'] ?? 'half_up',
        'levels' => [
            ['code' => 'PALLET960', 'uom_id' => pcUnit('PLT', 'Pallet'), 'units_per_parent' => 40, 'parent_code' => 'CARTON24', 'role' => 'logistic'],
            ['code' => 'PACK6', 'uom_id' => pcUnit('PACK', 'Pack'), 'units_per_parent' => 6, 'role' => 'sales'],
            ['code' => 'CARTON24', 'uom_id' => pcUnit('CT', 'Carton'), 'units_per_parent' => 4, 'parent_code' => 'PACK6', 'role' => 'purchase'],
        ],
    ])->assertOk();

    return $product;
}

it('chains the factor of every level to the base unit, whatever the order of the levels', function (): void {
    $product = pcPackedProduct($this);

    $detail = $this->getJson('/api/v1/catalog/products/'.$product['id'])->assertOk()->json('data');
    $levels = collect($detail['packagings'])->keyBy('code');

    expect($levels['PACK6']['quantity_base'])->toBe('6.000000')
        ->and($levels['CARTON24']['quantity_base'])->toBe('24.000000')
        ->and($levels['PALLET960']['quantity_base'])->toBe('960.000000')
        ->and($levels['PACK6']['level'])->toBe(1)
        ->and($levels['PALLET960']['level'])->toBe(3)
        ->and($levels['CARTON24']['parent_packaging_id'])->toBe($levels['PACK6']['id']);
});

it('follows the first purchase and sales packaging for the default units of the product', function (): void {
    $product = pcPackedProduct($this);

    $detail = $this->getJson('/api/v1/catalog/products/'.$product['id'])->assertOk()->json('data');

    expect($detail['sales_uom_id'])->toBe(pcUnit('PACK'))->and($detail['purchase_uom_id'])->toBe(pcUnit('CT'));
});

it('converts a quantity to every unit and to the base unit, both ways', function (): void {
    $product = pcPackedProduct($this);
    $url = '/api/v1/catalog/products/'.$product['id'].'/convert';

    $fromCarton = $this->postJson($url, ['quantity' => 2, 'unit' => 'CARTON24'])->assertOk()->json('data');

    expect($fromCarton['base'])->toBe('48')
        ->and($fromCarton['units']['PACK6'])->toBe('8')
        ->and($fromCarton['units']['CARTON24'])->toBe('2')
        ->and($fromCarton['units']['PALLET960'])->toBe('0.05')
        ->and($fromCarton['units']['BASE'])->toBe('48');

    $fromBase = $this->postJson($url, ['quantity' => 48, 'unit' => 'base'])->assertOk()->json('data');

    expect($fromBase['units']['CARTON24'])->toBe('2');
});

it('splits a quantity into whole pallets, cartons and packs with the remainder in base units', function (): void {
    $product = pcPackedProduct($this);

    $split = $this->postJson('/api/v1/catalog/products/'.$product['id'].'/convert', ['quantity' => 1030, 'unit' => 'BASE'])->assertOk()->json('data.split');

    expect($split['parts'])->toBe([
        ['unit' => 'PALLET960', 'quantity' => '1'],
        ['unit' => 'CARTON24', 'quantity' => '2'],
        ['unit' => 'PACK6', 'quantity' => '3'],
    ])->and($split['remainder'])->toBe('4');
});

it('rounds once following the policy of the product', function (string $rounding, string $expected): void {
    $product = pcPackedProduct($this, ['uom_precision' => 2, 'uom_rounding' => $rounding]);

    $units = $this->postJson('/api/v1/catalog/products/'.$product['id'].'/convert', ['quantity' => 1, 'unit' => 'BASE'])->assertOk()->json('data.units');

    expect($units['CARTON24'])->toBe($expected);
})->with([['half_up', '0.04'], ['up', '0.05'], ['down', '0.04']]);

it('refuses an inexact conversion when the policy is none', function (): void {
    $product = pcPackedProduct($this, ['uom_precision' => 2, 'uom_rounding' => 'none']);

    $this->postJson('/api/v1/catalog/products/'.$product['id'].'/convert', ['quantity' => 1, 'unit' => 'BASE'])->assertStatus(422)->assertJsonPath('code', 'conversion_not_exact');
    $this->postJson('/api/v1/catalog/products/'.$product['id'].'/convert', ['quantity' => 12, 'unit' => 'BASE'])->assertStatus(422);
});

it('refuses a unit the product does not have', function (): void {
    $product = pcPackedProduct($this);

    $this->postJson('/api/v1/catalog/products/'.$product['id'].'/convert', ['quantity' => 1, 'unit' => 'DRUM'])->assertStatus(422)->assertJsonPath('code', 'unknown_unit');
});

it('refuses a cycle, an unknown parent, a repeated code and a quantity of zero', function (): void {
    $product = pcProduct($this);
    $pz = pcUnit('PZ');
    $url = '/api/v1/catalog/products/'.$product['id'].'/packagings';

    $this->putJson($url, ['levels' => [
        ['code' => 'A', 'uom_id' => $pz, 'units_per_parent' => 2, 'parent_code' => 'B'],
        ['code' => 'B', 'uom_id' => $pz, 'units_per_parent' => 2, 'parent_code' => 'A'],
    ]])->assertStatus(422)->assertJsonPath('code', 'packaging_cycle');
    $this->putJson($url, ['levels' => [['code' => 'A', 'uom_id' => $pz, 'units_per_parent' => 2, 'parent_code' => 'NOPE']]])->assertStatus(422)->assertJsonPath('code', 'packaging_parent_unknown');
    $this->putJson($url, ['levels' => [['code' => 'A', 'uom_id' => $pz, 'units_per_parent' => 2], ['code' => 'a', 'uom_id' => $pz, 'units_per_parent' => 3]]])->assertStatus(422);
    $this->putJson($url, ['levels' => [['code' => 'A', 'uom_id' => $pz, 'units_per_parent' => 0]]])->assertStatus(422);
});

it('checks the GTIN of a packaging', function (): void {
    $product = pcProduct($this);
    $pz = pcUnit('PZ');
    $url = '/api/v1/catalog/products/'.$product['id'].'/packagings';

    $this->putJson($url, ['levels' => [['code' => 'CT', 'uom_id' => $pz, 'units_per_parent' => 24, 'gtin' => '14006381333938']]])->assertOk();
    $this->putJson($url, ['levels' => [['code' => 'CT', 'uom_id' => $pz, 'units_per_parent' => 24, 'gtin' => '14006381333939']]])->assertStatus(422)->assertJsonPath('code', 'gtin_invalid');
});

it('replaces the hierarchy every time and clears the default units when nothing is left', function (): void {
    $product = pcPackedProduct($this);

    $detail = $this->putJson('/api/v1/catalog/products/'.$product['id'].'/packagings', ['levels' => []])->assertOk()->json('data');

    expect($detail['packagings'])->toBe([])->and($detail['sales_uom_id'])->toBeNull()->and($detail['purchase_uom_id'])->toBeNull();
});

it('keeps fractional packagings exact: three thirds make 0.999999, not 1', function (): void {
    $product = pcProduct($this, ['uom_precision' => 6]);
    $this->putJson('/api/v1/catalog/products/'.$product['id'].'/packagings', ['levels' => [
        ['code' => 'THIRD', 'uom_id' => pcUnit('PZ'), 'units_per_parent' => '0.333333'],
    ]])->assertOk();

    $units = $this->postJson('/api/v1/catalog/products/'.$product['id'].'/convert', ['quantity' => 3, 'unit' => 'THIRD'])->assertOk()->json('data');

    expect($units['base'])->toBe('0.999999')->and($units['units']['THIRD'])->toBe('3');
});

it('does not convert units of a product of another company', function (): void {
    $other = pcOtherTenantProduct($this);

    $this->postJson('/api/v1/catalog/products/'.$other->id.'/convert', ['quantity' => 1, 'unit' => 'BASE'])->assertNotFound();
    $this->putJson('/api/v1/catalog/products/'.$other->id.'/packagings', ['levels' => []])->assertNotFound();
});
