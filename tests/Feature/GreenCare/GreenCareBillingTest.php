<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GreenCare\Models\CrewRouteStop;
use Modules\GreenCare\Models\GreenWasteSlip;
use Modules\GreenCare\Models\SeasonalMaintenanceContract;
use Modules\Invoicing\Models\SalesInvoice;

require_once __DIR__.'/GreenCareSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    gcSetUp($this);
    $this->crew = gcCrew($this);
});

/**
 * An Italian condominium: the fixed fee of 1200 euro over the six months of the season (200 a month) and a standby fee of 300 for the snow.
 */
function gcCondominiumContract(object $test, array $overrides = []): SeasonalMaintenanceContract
{
    $site = gcSite($test, ['condominium_administrator_id' => $test->administrator->id]);

    return gcContract($test, $site, array_merge([
        'condominium_administrator_id' => $test->administrator->id,
        'is_condominium' => true,
        'condominium_fiscal_code' => '90012345678',
        'apply_condominium_withholding' => true,
        'snow_removal_standby_fee' => 300,
        'snow_trigger_threshold_cm' => 5,
    ], $overrides));
}

test('the preview of an Italian condominium shows the 4 percent withholding on the taxable amount', function (): void {
    $contract = gcCondominiumContract($this);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertOk()
        ->assertJsonPath('data.lines.0.kind', 'installment')
        ->assertJsonPath('data.lines.0.net', '200.00')
        ->assertJsonPath('data.lines.1.kind', 'snow_standby')
        ->assertJsonPath('data.total_net', '500.00')
        ->assertJsonPath('data.total_vat', '110.00')
        ->assertJsonPath('data.withholding.applies', true)
        ->assertJsonPath('data.withholding.percent', 4)
        ->assertJsonPath('data.withholding.base', '500.00')
        ->assertJsonPath('data.withholding.amount', '20.00')
        ->assertJsonPath('data.net_payable', '590.00')
        ->assertJsonPath('data.condominium.fiscal_code', '90012345678')
        ->assertJsonPath('data.condominium.administrator_name', 'Studio Rossi Amministrazioni')
        ->assertJsonPath('data.invoice_id', null);

    expect(SalesInvoice::withoutGlobalScopes()->count())->toBe(0);
});

test('the invoice of a condominium carries the withholding and the FatturaPA metadata', function (): void {
    $contract = gcCondominiumContract($this);

    $id = $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertCreated()
        ->assertJsonPath('data.withholding.amount', '20.00')
        ->json('data.invoice_id');

    $invoice = SalesInvoice::withoutGlobalScopes()->findOrFail($id);
    $extra = json_decode((string) $invoice->getRawOriginal('sdi_extra'), true);

    expect($invoice->status->value ?? $invoice->status)->toBe('draft')
        ->and($invoice->contact_id)->toBe($contract->customer_id)
        ->and((bool) $invoice->withholding_tax_enabled)->toBeTrue()
        ->and((float) $invoice->withholding_tax_percent)->toBe(4.0)
        ->and((float) $invoice->withholding_tax_amount)->toBe(20.0)
        ->and((float) $invoice->total_net)->toBe(500.0)
        ->and($extra['FatturaElettronicaHeader']['CessionarioCommittente']['DatiAnagrafici']['CodiceFiscale'])->toBe('90012345678')
        ->and(implode(' ', $extra['FatturaElettronicaBody']['DatiGenerali']['DatiGeneraliDocumento']['Causale']))->toContain('Studio Rossi Amministrazioni')->toContain('Art. 25-ter DPR 600/73')
        ->and($invoice->items()->count())->toBe(2);

    $contract->refresh();
    expect($contract->installments_billed)->toBe(1)->and($contract->snow_standby_billed)->toBeTrue();
});

test('a second run bills only the next installment, the standby fee is billed once', function (): void {
    $contract = gcCondominiumContract($this);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertCreated();

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertCreated()
        ->assertJsonCount(1, 'data.lines')
        ->assertJsonPath('data.total_net', '200.00')
        ->assertJsonPath('data.withholding.amount', '8.00');

    expect($contract->refresh()->installments_billed)->toBe(2);
});

test('the last installment absorbs the rounding and then there is nothing left to bill', function (): void {
    $contract = gcContract($this, gcSite($this), ['fixed_fee_amount' => 1000, 'installment_frequency' => 'quarterly', 'season_start_date' => '2026-04-01', 'season_end_date' => '2026-12-31']);

    foreach (['333.33', '333.33', '333.34'] as $expected) {
        $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertOk()->assertJsonPath('data.lines.0.net', $expected);
        $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertCreated();
    }

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertStatus(422)->assertJsonPath('code', 'nothing_to_bill');
});

test('a contract that is not a condominium has no withholding', function (): void {
    $contract = gcContract($this, gcSite($this));

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertOk()
        ->assertJsonPath('data.withholding', null)
        ->assertJsonPath('data.condominium', null)
        ->assertJsonPath('data.net_payable', '244.00');
});

test('the fiscal code of the condominium is required to invoice with the withholding', function (): void {
    $contract = gcCondominiumContract($this, ['condominium_fiscal_code' => null]);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertStatus(422)->assertJsonPath('code', 'condominium_data_missing');
});

test('a draft contract cannot be billed', function (): void {
    $contract = gcContract($this, gcSite($this), ['status' => 'draft']);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertStatus(422)->assertJsonPath('code', 'contract_not_billable');
});

test('extra passages are billed once at the extra passage price and the stops are marked invoiced', function (): void {
    $site = gcSite($this);
    $contract = gcContract($this, $site, ['included_passages_count' => 1, 'completed_passages_count' => 1, 'fixed_fee_amount' => 0, 'extra_passage_unit_price' => 85]);
    [, $first] = gcStop($this, $this->crew, $site, $contract, '2026-05-04');
    [, $second] = gcStop($this, $this->crew, $site, $contract, '2026-05-05');
    gcCloseStop($this, $first)->assertOk();
    gcCloseStop($this, $second)->assertOk();

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertOk()
        ->assertJsonCount(2, 'data.lines')
        ->assertJsonPath('data.lines.0.kind', 'extra_passage')
        ->assertJsonPath('data.total_net', '170.00')
        ->assertJsonPath('data.total_vat', '37.40');

    $id = $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertCreated()->json('data.invoice_id');

    expect(CrewRouteStop::query()->where('invoice_id', $id)->count())->toBe(2)
        ->and(CrewRouteStop::query()->where('billing_status', 'invoiced')->count())->toBe(2);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice')->assertStatus(422)->assertJsonPath('code', 'nothing_to_bill');
});

test('time and material bills the logged hours with the plants and the billable green waste', function (): void {
    $site = gcSite($this);
    $contract = gcContract($this, $site, ['billing_mode' => 'time_and_material', 'included_passages_count' => 0, 'fixed_fee_amount' => 0, 'time_and_material_hourly_rate' => 50]);
    $warehouse = tfrWarehouse($this->tenant->id, 'GC2', ['is_primary' => true]);
    $plant = tfrProduct($this->tenant->id, 'ROSA-01', 5.0, 12.0);
    [, $stop] = gcStop($this, $this->crew, $site, $contract);

    gcCloseStop($this, $stop, [
        'crew_hours' => [['member_id' => gcOperator($this->crew)->id, 'hours' => 3], ['member_id' => gcOperator($this->crew)->id, 'hours' => 1]],
        'materials' => [['product_id' => $plant->id, 'quantity' => 4, 'warehouse_id' => $warehouse->id]],
        'green_waste_weight_kg' => 120,
    ])->assertOk();

    GreenWasteSlip::query()->where('route_stop_id', $stop->id)->update(['disposal_cost' => 36.5, 'is_billable_to_customer' => true]);

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertOk()
        ->assertJsonCount(3, 'data.lines')
        ->assertJsonPath('data.lines.0.kind', 'time_and_material')
        ->assertJsonPath('data.lines.0.quantity', '4')
        ->assertJsonPath('data.lines.0.net', '200.00')
        ->assertJsonPath('data.lines.1.kind', 'material')
        ->assertJsonPath('data.lines.1.net', '48.00')
        ->assertJsonPath('data.lines.2.kind', 'waste')
        ->assertJsonPath('data.lines.2.net', '36.50')
        ->assertJsonPath('data.total_net', '284.50');

    $this->postJson(GC_API.'/contracts/'.$contract->id.'/generate-invoice', ['include_waste' => false])->assertCreated()->assertJsonCount(2, 'data.lines');

    expect(GreenWasteSlip::query()->whereNotNull('invoice_id')->count())->toBe(0);
});

test('Swiss contracts bill the Swiss VAT without withholding and the regia rate when no rate is stated', function (): void {
    $site = gcSite($this, ['jurisdiction' => 'CH']);
    $contract = gcContract($this, $site, ['jurisdiction' => 'CH', 'vat_rate' => 8.1, 'billing_mode' => 'time_and_material', 'included_passages_count' => 0, 'fixed_fee_amount' => 0, 'time_and_material_hourly_rate' => 0]);
    [, $stop] = gcStop($this, $this->crew, $site, $contract);
    gcCloseStop($this, $stop, ['crew_hours' => [['member_id' => gcOperator($this->crew)->id, 'hours' => 2]]])->assertOk();

    // The JardinSuisse regia rate of the gardener (68.00) applies.
    $this->postJson(GC_API.'/contracts/'.$contract->id.'/billing-preview')->assertOk()
        ->assertJsonPath('data.jurisdiction', 'CH')
        ->assertJsonPath('data.withholding', null)
        ->assertJsonPath('data.total_net', '136.00')
        ->assertJsonPath('data.total_vat', '11.02');
});
