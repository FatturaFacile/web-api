<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use Modules\BondedWarehouses\Models\VatDepotRegisterEntry;
use Modules\Invoicing\Models\SalesInvoice;

require_once __DIR__.'/BondedWarehousesSupport.php';

uses(RefreshDatabase::class);

/**
 * An IVA warehouse (Art. 50-bis) holding 100 units of the product loaded in free circulation.
 */
function bwVatDepot(object $test): CustomsWarehouseAuthorization
{
    $depot = bwAuthorization($test, ['regime_type' => 'VatDepot_Art50Bis', 'cgu_max_ceiling_amount' => 0, 'aeo_status' => 'None', 'aeo_certificate_number' => null, 'aeo_next_audit_date' => null]);

    $test->postJson(BW_API.'/vat-register/inbound', [
        'authorization_id' => $depot->id, 'movement_type' => 'Inbound_FreeCirculation', 'movement_date' => '2027-06-01',
        'product_id' => $test->product->id, 'lot_reference' => 'L-1', 'quantity' => 100, 'base_goods_value' => 2500,
    ])->assertCreated();

    return $depot;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bwExtraction(object $test, CustomsWarehouseAuthorization $depot, array $overrides = []): array
{
    return array_merge([
        'authorization_id' => $depot->id, 'movement_type' => 'Extraction_TD22', 'movement_date' => '2027-07-05',
        'product_id' => $test->product->id, 'lot_reference' => 'L-1', 'quantity' => 40,
        'base_goods_value' => 1000, 'storage_and_processing_services_value' => 200, 'vat_rate_percent' => 22,
    ], $overrides);
}

test('the load rows are numbered progressively per warehouse', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);

    $this->postJson(BW_API.'/vat-register/inbound', [
        'authorization_id' => $depot->id, 'movement_type' => 'Inbound_IntraEU', 'movement_date' => '2027-06-10',
        'product_id' => $this->product->id, 'lot_reference' => 'L-2', 'quantity' => 10, 'base_goods_value' => 100,
    ])->assertCreated()->assertJsonPath('data.register_progressive_number', 2)->assertJsonPath('data.vat_amount', '0.00')->assertJsonPath('data.sdi_document_type', 'None');

    $rows = $this->getJson(BW_API.'/vat-register?authorization_id='.$depot->id)->assertOk()->json('data');

    expect(array_column($rows, 'register_progressive_number'))->toBe([1, 2]);
});

test('an extraction adds the services to the goods, emits the TD22 self-invoice and writes the register row', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);

    $response = $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot))->assertCreated()
        ->assertJsonPath('data.register_progressive_number', 2)
        ->assertJsonPath('data.total_taxable_extraction_base', '1200.00')
        ->assertJsonPath('data.vat_amount', '264.00')
        ->assertJsonPath('data.sdi_document_type', 'TD22')
        ->assertJsonPath('data.movement_type', 'Extraction_TD22');

    $invoice = SalesInvoice::query()->findOrFail($response->json('data.self_invoice_id'));

    expect($invoice->document_type)->toBe('TD22')
        ->and((string) $invoice->contact_id)->toBe((string) $this->selfContact->id)
        ->and((float) $invoice->total_net)->toBe(1200.0)
        ->and((float) $invoice->total_tax)->toBe(264.0)
        ->and($invoice->items)->toHaveCount(2);
});

test('a TD23 extraction needs the F24 payment and keeps its reference', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);
    $payload = bwExtraction($this, $depot, ['movement_type' => 'Extraction_TD23']);

    $this->postJson(BW_API.'/vat-register/extraction', $payload)->assertStatus(422)->assertJsonValidationErrors(['f24_payment_reference', 'f24_payment_date']);

    $response = $this->postJson(BW_API.'/vat-register/extraction', $payload + ['f24_payment_reference' => 'F24-2027-07-0001', 'f24_payment_date' => '2027-07-04'])->assertCreated()
        ->assertJsonPath('data.sdi_document_type', 'TD23')
        ->assertJsonPath('data.f24_payment_reference', 'F24-2027-07-0001')
        ->assertJsonPath('data.f24_payment_date', '2027-07-04');

    expect(SalesInvoice::query()->findOrFail($response->json('data.self_invoice_id'))->document_type)->toBe('TD23');

    $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot, ['f24_payment_reference' => 'F24-X', 'f24_payment_date' => '2027-07-04']))->assertStatus(422);
});

test('an extraction with the letter of intent carries no VAT and issues a TD22', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);

    $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot, ['movement_type' => 'Extraction_LetterOfIntent', 'vat_rate_percent' => 22]))->assertCreated()
        ->assertJsonPath('data.vat_amount', '0.00')
        ->assertJsonPath('data.vat_rate_percent', '0.0000')
        ->assertJsonPath('data.sdi_document_type', 'TD22');
});

test('an extraction beyond the stock of the register is refused and writes nothing', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);

    $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot, ['quantity' => 101]))->assertStatus(422)->assertJsonPath('code', 'insufficient_depot_stock');
    $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot))->assertCreated();
    $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot, ['quantity' => 61]))->assertStatus(422)->assertJsonPath('code', 'insufficient_depot_stock');

    expect(VatDepotRegisterEntry::query()->count())->toBe(2)->and(SalesInvoice::query()->count())->toBe(1);
});

test('without the contact of the own company no self-invoice is issued and nothing is written', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);
    $this->selfContact->forceDelete();

    $this->postJson(BW_API.'/vat-register/extraction', bwExtraction($this, $depot))->assertStatus(422)->assertJsonPath('code', 'self_contact_missing');

    expect(VatDepotRegisterEntry::query()->count())->toBe(1)->and(SalesInvoice::query()->count())->toBe(0);
});

test('the preview calculates the base, the VAT and the document without writing', function (): void {
    bwSetUp($this);
    $depot = bwVatDepot($this);

    $this->postJson(BW_API.'/vat-register/extraction/preview', bwExtraction($this, $depot, ['movement_type' => 'Extraction_TD23', 'f24_payment_reference' => 'F24-1', 'f24_payment_date' => '2027-07-04']))->assertOk()
        ->assertJsonPath('data.total_taxable_extraction_base', 1200.0)
        ->assertJsonPath('data.vat_amount', 264.0)
        ->assertJsonPath('data.total_with_vat', 1464.0)
        ->assertJsonPath('data.sdi_document_type', 'TD23')
        ->assertJsonPath('data.available_quantity', 100.0)
        ->assertJsonPath('data.sufficient_stock', true)
        ->assertJsonPath('data.requires_f24', true);

    expect(VatDepotRegisterEntry::query()->count())->toBe(1)->and(SalesInvoice::query()->count())->toBe(0);
});

test('the register only works on IVA warehouses and its rows cannot be changed', function (): void {
    bwSetUp($this);
    $customs = bwAuthorization($this);

    $this->postJson(BW_API.'/vat-register/inbound', [
        'authorization_id' => $customs->id, 'movement_type' => 'Inbound_FreeCirculation', 'movement_date' => '2027-06-01',
        'product_id' => $this->product->id, 'quantity' => 1, 'base_goods_value' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'regime_not_vat_depot');

    $depot = bwVatDepot($this);
    $entry = VatDepotRegisterEntry::query()->where('authorization_id', $depot->id)->firstOrFail();

    expect(fn () => $entry->update(['quantity' => 1]))->toThrow(BondedWarehouseException::class)
        ->and(fn () => $entry->delete())->toThrow(BondedWarehouseException::class);

    $this->get(BW_API.'/exports/vat-register.csv')->assertOk();
});
