<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\ProductCatalog\Domain\Support\Gtin;

require_once __DIR__.'/ProductCatalogSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    pcSetUp($this);
});

it('creates a product of every type and reports its type', function (string $type): void {
    $data = pcProduct($this, ['product_type' => $type, 'sku' => 'T-'.$type]);

    expect($data['product_type'])->toBe($type)->and($data['kit_type'])->toBeNull()->and($data['is_variant'])->toBeFalse();
})->with(['storable', 'consumable', 'service', 'digital']);

it('does not stock services and digital goods, whatever the form says', function (string $type): void {
    $data = pcProduct($this, ['product_type' => $type, 'track_inventory' => true]);

    expect($data['track_inventory'])->toBeFalse();
})->with(['service', 'digital']);

it('keeps stock tracking of storable and consumable items', function (): void {
    expect(pcProduct($this, ['track_inventory' => true])['track_inventory'])->toBeTrue()
        ->and(pcProduct($this, ['product_type' => 'consumable', 'track_inventory' => true])['track_inventory'])->toBeTrue();
});

it('forces a phantom kit off stock and a physical kit on stock', function (): void {
    $phantom = pcProduct($this, ['kit_type' => 'phantom', 'track_inventory' => true]);
    $physical = pcProduct($this, ['kit_type' => 'physical', 'track_inventory' => false]);

    expect($phantom['kit_type'])->toBe('phantom')->and($phantom['track_inventory'])->toBeFalse()
        ->and($physical['kit_type'])->toBe('physical')->and($physical['track_inventory'])->toBeTrue();
});

it('refuses a kit that is a service or a digital good', function (string $type): void {
    $this->postJson('/api/v1/catalog/products', pcPayload(['product_type' => $type, 'kit_type' => 'phantom']))
        ->assertStatus(422)->assertJsonPath('code', 'kit_type_not_allowed');
})->with(['service', 'digital']);

it('refuses a duplicated SKU in the same company but accepts it in another one', function (): void {
    pcProduct($this, ['sku' => 'DUP-1']);

    $this->postJson('/api/v1/catalog/products', pcPayload(['sku' => 'DUP-1']))->assertStatus(422)->assertJsonValidationErrors(['sku']);

    pcOtherTenantProduct($this, 'DUP-2');
    $this->postJson('/api/v1/catalog/products', pcPayload(['sku' => 'DUP-2']))->assertCreated();
});

it('refuses a barcode with a wrong GS1 check digit and one that belongs to another product', function (): void {
    $this->postJson('/api/v1/catalog/products', pcPayload(['barcode' => '4006381333932']))->assertStatus(422)->assertJsonPath('code', 'gtin_invalid');

    pcProduct($this, ['barcode' => '4006381333931']);
    $this->postJson('/api/v1/catalog/products', pcPayload(['barcode' => '4006381333931']))->assertStatus(422)->assertJsonPath('code', 'barcode_taken');

    // A barcode that is not of a GTIN length (Code 128, internal label) is not checked.
    $this->postJson('/api/v1/catalog/products', pcPayload(['barcode' => 'LABEL-0001']))->assertCreated();
});

it('generates a valid in-house EAN-13 on request, different for every product', function (): void {
    $first = pcProduct($this, ['generate_barcode' => true])['barcode'];
    $second = pcProduct($this, ['generate_barcode' => true])['barcode'];

    expect($first)->toHaveLength(13)->and($first)->toStartWith('2')->and($second)->not->toBe($first)
        ->and(Gtin::isValid($second))->toBeTrue();
});

it('stores the Swiss Tares number, the TARIC code, the origin and both VAT codes', function (): void {
    $it = pcVat('IVA22', 'IT', '22');
    $ch = pcVat('CH_MWST_81', 'CH', '8.1');

    $data = pcProduct($this, [
        'tares_code' => '85176200', 'taric_code' => '8517620000', 'country_of_origin' => 'cn', 'customs_duty_rate' => '0',
        'vat_code_it_id' => $it, 'vat_code_ch_id' => $ch,
    ]);

    expect($data['tares_code'])->toBe('85176200')->and($data['taric_code'])->toBe('8517620000')->and($data['country_of_origin'])->toBe('CN')
        ->and($data['vat_code_it_id'])->toBe($it)->and($data['vat_code_ch_id'])->toBe($ch);
    expect(Product::query()->findOrFail($data['id'])->swiss_hs_code)->toBe('85176200');
});

it('refuses malformed customs codes and a VAT code of the wrong country', function (): void {
    $this->postJson('/api/v1/catalog/products', pcPayload(['tares_code' => '123']))->assertStatus(422)->assertJsonValidationErrors(['tares_code']);
    $this->postJson('/api/v1/catalog/products', pcPayload(['taric_code' => '12345']))->assertStatus(422)->assertJsonValidationErrors(['taric_code']);

    $ch = pcVat('CH_MWST_81', 'CH', '8.1');
    $this->postJson('/api/v1/catalog/products', pcPayload(['vat_code_it_id' => $ch]))->assertStatus(422)->assertJsonPath('code', 'vat_code_country_mismatch');
});

it('derives the unit name from the unit of measure and refuses a unit it does not know', function (): void {
    $unit = pcUnit('KG', 'Kilogram');

    expect(pcProduct($this, ['unit_of_measure_id' => $unit])['unit_measure'])->toBe('KG');

    $this->postJson('/api/v1/catalog/products', pcPayload(['unit_of_measure_id' => (string) Str::uuid()]))->assertStatus(422);
});

it('updates a product and keeps the type rules', function (): void {
    $service = pcProduct($this, ['product_type' => 'service']);

    $this->putJson('/api/v1/catalog/products/'.$service['id'], ['name' => 'Renamed', 'track_inventory' => true])
        ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.track_inventory', false);
});

it('lists the catalog with filters and paging, parents first', function (): void {
    pcProduct($this, ['sku' => 'A-1', 'name' => 'Blue chair']);
    pcProduct($this, ['sku' => 'B-1', 'name' => 'Red table', 'product_type' => 'service']);
    pcProduct($this, ['sku' => 'C-1', 'name' => 'Kit one', 'kit_type' => 'phantom']);

    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonPath('meta.total', 3);
    $this->getJson('/api/v1/catalog/products?q=chair')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.sku', 'A-1');
    $this->getJson('/api/v1/catalog/products?product_type=service')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/catalog/products?kit_type=phantom')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/catalog/products?per_page=2')->assertOk()->assertJsonPath('meta.last_page', 2);
    $this->getJson('/api/v1/catalog/products?product_type=unknown')->assertStatus(422);
});

it('shows a product with its axes, variants, packagings and kit components', function (): void {
    $product = pcProduct($this);

    $this->getJson('/api/v1/catalog/products/'.$product['id'])->assertOk()
        ->assertJsonPath('data.sku', $product['sku'])
        ->assertJsonPath('data.axes', [])
        ->assertJsonPath('data.variants', [])
        ->assertJsonPath('data.packagings', [])
        ->assertJsonPath('data.kit_components', []);
});

it('never shows or changes the products of another company', function (): void {
    $other = pcOtherTenantProduct($this);

    $this->getJson('/api/v1/catalog/products/'.$other->id)->assertNotFound();
    $this->putJson('/api/v1/catalog/products/'.$other->id, ['name' => 'Hacked'])->assertNotFound();
    $this->deleteJson('/api/v1/catalog/products/'.$other->id)->assertNotFound();
    $this->getJson('/api/v1/catalog/products')->assertOk()->assertJsonPath('meta.total', 0);
});

it('deletes a product with its variants but not a component of a kit', function (): void {
    $product = pcProduct($this);
    $this->deleteJson('/api/v1/catalog/products/'.$product['id'])->assertOk();
    $this->getJson('/api/v1/catalog/products/'.$product['id'])->assertNotFound();

    $component = pcProduct($this);
    $kit = pcProduct($this, ['kit_type' => 'phantom']);
    $this->putJson('/api/v1/catalog/products/'.$kit['id'].'/kit-components', ['components' => [['component_id' => $component['id'], 'quantity' => 1]]])->assertOk();

    $this->deleteJson('/api/v1/catalog/products/'.$component['id'])->assertStatus(409)->assertJsonPath('code', 'product_in_use_by_kit');
});

it('answers the lookups with the codes, units, VAT codes of IT and CH, categories and brands', function (): void {
    pcUnit('PZ', 'Piece');
    pcVat('IVA22', 'IT', '22');
    pcVat('CH_MWST_81', 'CH', '8.1');

    $data = $this->getJson('/api/v1/catalog/lookups')->assertOk()->json('data');

    expect($data['product_types'])->toBe(['storable', 'consumable', 'service', 'digital'])
        ->and($data['kit_types'])->toBe(['phantom', 'physical'])
        ->and($data['rounding_modes'])->toContain('half_up', 'none')
        ->and(collect($data['units'])->pluck('code')->all())->toContain('PZ')
        ->and(collect($data['vat_codes'])->pluck('country')->unique()->sort()->values()->all())->toBe(['CH', 'IT']);
});

it('searches the customs code catalog', function (): void {
    $this->getJson('/api/v1/catalog/customs-codes?q=91')->assertOk()->assertJsonStructure(['data']);
});

it('lets a read-only member read but not write', function (): void {
    pcSetUp($this, 'accountant_readonly');

    $this->getJson('/api/v1/catalog/products')->assertOk();
    $this->postJson('/api/v1/catalog/products', pcPayload())->assertForbidden();
});

it('refuses the catalog to a user outside the company', function (): void {
    $stranger = User::create(['name' => 'Estraneo', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    Sanctum::actingAs($stranger);

    expect($this->getJson('/api/v1/catalog/products')->status())->toBeIn([403, 404]);
});
