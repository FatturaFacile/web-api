<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\OliveMillAndGrainMill\Models\MillingSettlement;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

require_once __DIR__.'/OliveMillSupport.php';

uses(RefreshDatabase::class);

/**
 * The service priced at 8 EUR per quintal of olives (4000 kg = 40 quintals: 320.00 net, 390.40 with 22 % VAT) settled with 10 % of the oil (600 kg)
 * kept at 8 EUR per kg (60 kg = 480.00 net, 499.20 with 4 % VAT).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function omHybrid(array $overrides = []): array
{
    return array_merge([
        'tariff_mode' => 'PER_QUINTAL_OLIVES', 'tariff_value' => 8, 'settlement_mode' => 'HYBRID_MOLENDA', 'molenda_percent' => 10, 'oil_price_per_kg' => 8,
    ], $overrides);
}

test('the preview shows both documents and the cash balance and writes nothing', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/preview-settlement', omHybrid())
        ->assertOk()
        ->assertJsonPath('data.available_kg', '200.000')
        ->assertJsonPath('data.calculation.service_net', '320.00')
        ->assertJsonPath('data.calculation.service_vat', '70.40')
        ->assertJsonPath('data.calculation.service_total', '390.40')
        ->assertJsonPath('data.calculation.retained_kg', '60.000')
        ->assertJsonPath('data.calculation.retained_value_net', '480.00')
        ->assertJsonPath('data.calculation.purchase_total', '499.20')
        ->assertJsonPath('data.calculation.cash_balance', '-108.80')
        ->assertJsonPath('data.calculation.cash_direction', 'mill_pays')
        ->assertJsonPath('data.documents.service_invoice.total', '390.40')
        ->assertJsonPath('data.documents.purchase_document.total', '499.20')
        ->assertJsonPath('data.requires_tank', true);

    expect(MillingSettlement::query()->count())->toBe(0)->and(SalesInvoice::query()->count())->toBe(0)->and(PassiveInvoice::query()->count())->toBe(0);
});

test('the settlement creates the invoice, the purchase document, the SIAN load and the compensation in one step', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    $cycle = omCompletedCycle($this);

    $response = $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid(['tank_id' => $tank->id]))
        ->assertCreated()
        ->assertJsonPath('data.status', 'SETTLED')
        ->assertJsonPath('data.service_total', '390.40')
        ->assertJsonPath('data.retained_kg', '60.000')
        ->assertJsonPath('data.purchase_total', '499.20')
        ->assertJsonPath('data.cash_balance', '-108.80')
        ->assertJsonPath('data.compensation_amount', '390.40');

    $settlement = MillingSettlement::query()->findOrFail($response->json('data.id'));
    $invoice = SalesInvoice::query()->findOrFail($settlement->service_invoice_id);
    $purchase = PassiveInvoice::query()->findOrFail($settlement->purchase_doc_id);

    expect((float) $invoice->total_net)->toBe(320.0)
        ->and((float) $invoice->total_gross)->toBe(390.4)
        ->and((string) $invoice->contact_id)->toBe((string) $this->farmer->id)
        ->and((string) $purchase->supplier_id)->toBe((string) $this->farmer->id)
        ->and((float) $purchase->total_amount)->toBe(499.2)
        ->and((float) $purchase->total_net)->toBe(480.0)
        ->and((float) $purchase->total_tax)->toBe(19.2)
        ->and($purchase->invoice_number)->toBe('MOL-2027-OL-00001')
        ->and($purchase->lines()->count())->toBe(1);

    $tank->refresh();
    expect($tank->stock_kg)->toBe('60.000')->and($tank->category?->value)->toBe('EVO');

    $load = SianMovement::query()->where('causal', 'C03')->firstOrFail();

    expect($load->quantity_kg)->toBe('60.000')
        ->and($load->to_tank_id)->toBe($tank->id)
        ->and($load->counterparty_cuaa)->toBe('RSSMRA85T10A562S')
        ->and((string) $settlement->sian_movement_id)->toBe((string) $load->id);

    // The 140 kg left at the mill and not retained are handed back to the farmer.
    expect(SianMovement::query()->where('causal', 'S02')->orderBy('progressive')->pluck('quantity_kg')->all())->toBe(['400.000', '140.000']);

    $cycle->refresh();
    expect($cycle->status->value)->toBe('SETTLED')->and($cycle->molenda_kg)->toBe('60.000')->and($cycle->pickup_kg)->toBe('540.000')
        ->and($cycle->delivery->status->value)->toBe('SETTLED');
});

test('a cash settlement only invoices the service', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    $response = $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', ['tariff_mode' => 'PER_QUINTAL_OLIVES', 'tariff_value' => 8, 'settlement_mode' => 'CASH_OR_BANK'])
        ->assertCreated()
        ->assertJsonPath('data.retained_kg', '0.000')
        ->assertJsonPath('data.cash_balance', '390.40')
        ->assertJsonPath('data.purchase_doc_id', null)
        ->assertJsonPath('data.tank_id', null);

    expect(SalesInvoice::query()->count())->toBe(1)->and(PassiveInvoice::query()->count())->toBe(0)
        ->and(SianMovement::query()->where('causal', 'C03')->count())->toBe(0)
        ->and(SianMovement::query()->where('causal', 'S02')->orderBy('progressive')->pluck('quantity_kg')->all())->toBe(['400.000', '200.000'])
        ->and($response->json('data.service_invoice_id'))->not->toBeNull();
});

test('a full in-kind settlement keeps the oil that covers the service', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S2']);
    $cycle = omCompletedCycle($this);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', [
        'tariff_mode' => 'FLAT_FEE', 'tariff_value' => 100, 'settlement_mode' => 'FULL_IN_KIND_MOLENDA', 'oil_price_per_kg' => 10, 'tank_id' => $tank->id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.service_total', '122.00')
        ->assertJsonPath('data.retained_kg', '11.731')
        ->assertJsonPath('data.purchase_total', '122.00')
        ->assertJsonPath('data.cash_balance', '0.00');

    expect($tank->refresh()->stock_kg)->toBe('11.731');
});

test('the pure percentage tariff cannot be paid in cash', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/preview-settlement', ['tariff_mode' => 'PURE_PERCENTAGE_MOLENDA', 'tariff_value' => 8, 'settlement_mode' => 'CASH_OR_BANK'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'percentage_tariff_requires_in_kind');
});

test('the oil to retain cannot exceed what was left at the mill and nothing is written', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S3']);
    $cycle = omCompletedCycle($this);
    $movements = SianMovement::query()->count();

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid(['molenda_percent' => 50, 'tank_id' => $tank->id]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'retained_exceeds_available');

    expect(MillingSettlement::query()->count())->toBe(0)->and(SalesInvoice::query()->count())->toBe(0)->and(SianMovement::query()->count())->toBe($movements)
        ->and($cycle->refresh()->status->value)->toBe('COMPLETED');
});

test('the retained oil needs a tank, and a refusal later in the flow undoes the invoice', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid())->assertUnprocessable()->assertJsonPath('code', 'target_tank_required');

    $small = omTank($this, ['code' => 'S4', 'capacity_kg' => 10]);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid(['tank_id' => $small->id]))->assertUnprocessable()->assertJsonPath('code', 'tank_capacity_exceeded');

    expect(SalesInvoice::query()->count())->toBe(0)->and(PassiveInvoice::query()->count())->toBe(0)->and($small->refresh()->stock_kg)->toBe('0.000');
});

test('DOP oil retained by the mill cannot go into a tank of another category', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S5']);
    omStock($this, $tank, 300, 'EVO');
    $cycle = omCompletedCycle($this, [], ['certification' => 'DOP']);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid(['tank_id' => $tank->id]))->assertUnprocessable()->assertJsonPath('code', 'dop_igp_mixing');
});

test('a cycle is settled once', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);
    $payload = ['tariff_mode' => 'PER_QUINTAL_OLIVES', 'tariff_value' => 8, 'settlement_mode' => 'CASH_OR_BANK'];

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', $payload)->assertCreated();
    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', $payload)->assertUnprocessable()->assertJsonPath('code', 'cycle_already_settled');

    expect(SalesInvoice::query()->count())->toBe(1);
});

test('only a completed third-party cycle can be settled', function (): void {
    omSetUp($this);
    $open = omStartCycle($this, omDelivery($this));
    $payload = ['tariff_mode' => 'PER_QUINTAL_OLIVES', 'tariff_value' => 8, 'settlement_mode' => 'CASH_OR_BANK'];

    $this->postJson(OM_API.'/milling-cycles/'.$open->id.'/settle', $payload)->assertUnprocessable()->assertJsonPath('code', 'cycle_not_completed');

    $own = omStock($this, omTank($this, ['code' => 'S6']), 300);

    $this->postJson(OM_API.'/milling-cycles/'.$own->id.'/settle', $payload)->assertUnprocessable()->assertJsonPath('code', 'cycle_already_settled');
});

test('the service amount must not be zero', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', ['tariff_mode' => 'FLAT_FEE', 'tariff_value' => 0, 'settlement_mode' => 'CASH_OR_BANK'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'service_amount_zero');
});

test('the oil price is required whenever oil is paid in kind', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid(['oil_price_per_kg' => null]))->assertUnprocessable()->assertJsonValidationErrors('oil_price_per_kg');
});

test('the settlements are listed with the lot and the farmer', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);
    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', ['tariff_mode' => 'PER_QUINTAL_OLIVES', 'tariff_value' => 8, 'settlement_mode' => 'CASH_OR_BANK'])->assertCreated();

    $this->getJson(OM_API.'/settlements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.lot_code', '2027-OL-00001')->assertJsonPath('data.0.farmer_name', 'Azienda Agricola Rossi');
    $this->getJson(OM_API.'/settlements?settlement_mode=HYBRID_MOLENDA')->assertOk()->assertJsonCount(0, 'data');
});

test('a read-only member can simulate a settlement but not execute it', function (): void {
    omSetUp($this);
    $cycle = omCompletedCycle($this);

    treasuryActor($this, 'accountant_readonly');

    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/preview-settlement', omHybrid())->assertOk();
    $this->postJson(OM_API.'/milling-cycles/'.$cycle->id.'/settle', omHybrid())->assertForbidden();
});
