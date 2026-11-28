<?php

declare(strict_types=1);

use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use Modules\DairyCheeseManufacturing\Models\PricingGrid;

require_once __DIR__.'/DairyCheeseSupport.php';

beforeEach(fn () => dcSetUp($this));

/**
 * 3,090 kg delivered in May (1,000 l and 2,000 l at 1.03), one analysis with 4.2 % fat and 150,000 SCC, and a vat of 1,000 l
 * (1,030 kg) processed on 20 May.
 *
 * @return array{breeder: string, grid: string}
 */
function dcSettlementCase(object $test): array
{
    $grid = (string) $test->postJson(DC_API.'/pricing-grids', [
        'name' => 'Gruyère test', 'jurisdiction' => 'CH', 'cheese_type' => 'gruyere_aop', 'base_price_per_kg' => 0.70, 'cheese_milk_supplement_per_kg' => 0.15, 'dop_premium_per_kg' => 0.05,
        'valid_from' => '2026-01-01', 'tiers' => [
            ['component' => 'fat', 'from_value' => 4.0, 'to_value' => null, 'adjustment_per_kg' => 0.01],
            ['component' => 'scc', 'from_value' => 400000, 'to_value' => null, 'adjustment_per_kg' => -0.03],
        ],
    ])->assertCreated()->json('data.id');

    $breeder = dcBreeder($test, 'CH-001');
    $tank = dcTank($test);
    dcDelivery($test, $breeder, $tank, '2026-05-10', 'morning', 1000);
    dcDelivery($test, $breeder, $tank, '2026-05-11', 'morning', 2000);
    $test->postJson(DC_API.'/quality-tests', ['breeder_id' => $breeder, 'sample_date' => '2026-05-12', 'fat_pct' => 4.2, 'protein_pct' => 3.4, 'scc_cells_ml' => 150000, 'inhibitors' => 'negative'])->assertCreated();
    $test->postJson(DC_API.'/vat-batches', ['cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-20', 'tank_usages' => [['tank_id' => $tank, 'liters' => 1000]]])->assertCreated();

    return ['breeder' => $breeder, 'grid' => $grid];
}

it('prices the month of a breeder: base, quality bonus, cheese-milk supplement, DOP premium and VAT', function (): void {
    $case = dcSettlementCase($this);

    $data = $this->postJson(DC_API.'/settlements/generate', ['period' => '2026-05', 'grid_id' => $case['grid']])->assertCreated()->json('data.settlements');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toMatchArray([
            'status' => 'draft', 'currency' => 'CHF', 'total_kg' => '3090.000', 'total_liters' => '3000.000', 'avg_fat_pct' => '4.200',
            'base_amount' => '2163.00', 'quality_adjustment' => '30.90', 'supplement_amount' => '206.00', 'penalty_amount' => '0.00',
            'gross_amount' => '2399.90', 'vat_amount' => '62.40', 'net_amount' => '2462.30',
        ])
        ->and(array_column($data[0]['lines'], 'component'))->toBe(['base', 'fat', 'cheese_milk_supplement', 'dop_premium'])
        ->and($data[0]['lines'][0]['label_key'])->toBe('DAIRY.SETTLEMENT.LINE.BASE');
});

it('regenerates a draft settlement instead of duplicating it and locks it once approved', function (): void {
    $case = dcSettlementCase($this);
    $payload = ['period' => '2026-05', 'grid_id' => $case['grid']];

    $first = $this->postJson(DC_API.'/settlements/generate', $payload)->assertCreated()->json('data.settlements.0.id');
    $this->postJson(DC_API.'/settlements/generate', $payload)->assertCreated();
    $this->getJson(DC_API.'/settlements?period=2026-05')->assertOk()->assertJsonCount(1, 'data');

    $id = $this->getJson(DC_API.'/settlements?period=2026-05')->json('data.0.id');
    expect($id)->not->toBe($first);

    $this->postJson(DC_API."/settlements/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson(DC_API."/settlements/{$id}/approve")->assertUnprocessable()->assertJsonPath('code', 'settlement_not_draft');
    $this->postJson(DC_API.'/settlements/generate', $payload)->assertUnprocessable()->assertJsonPath('code', 'settlement_locked');
});

it('does not pay milk in quarantine and refuses a period without deliveries or a grid that is not valid', function (): void {
    $case = dcSettlementCase($this);

    $this->postJson(DC_API.'/settlements/generate', ['period' => '2026-04', 'grid_id' => $case['grid']])->assertUnprocessable()->assertJsonPath('code', 'no_deliveries');

    $this->postJson(DC_API.'/quality-tests', ['breeder_id' => $case['breeder'], 'sample_date' => '2026-05-10', 'inhibitors' => 'positive'])->assertCreated();
    $kg = $this->postJson(DC_API.'/settlements/generate', ['period' => '2026-05', 'grid_id' => $case['grid']])->assertCreated()->json('data.settlements.0.total_kg');
    expect($kg)->toBe('2060.000');

    $future = $this->postJson(DC_API.'/pricing-grids', ['name' => 'Futura', 'jurisdiction' => 'CH', 'base_price_per_kg' => 0.7, 'valid_from' => '2027-01-01'])->json('data.id');
    $this->postJson(DC_API.'/settlements/generate', ['period' => '2026-05', 'grid_id' => $future])->assertUnprocessable()->assertJsonPath('code', 'grid_not_valid');
});

it('creates the billing document of an approved settlement as a purchase invoice of the breeder', function (): void {
    $case = dcSettlementCase($this);
    $id = $this->postJson(DC_API.'/settlements/generate', ['period' => '2026-05', 'grid_id' => $case['grid']])->json('data.settlements.0.id');

    $this->postJson(DC_API."/settlements/{$id}/document", ['document_type' => 'self_billing'])->assertUnprocessable()->assertJsonPath('code', 'settlement_not_approved');
    $this->postJson(DC_API."/settlements/{$id}/approve")->assertOk();
    $this->postJson(DC_API."/settlements/{$id}/document", ['document_type' => 'self_billing'])->assertUnprocessable()->assertJsonPath('code', 'breeder_contact_missing');

    $contact = dcContact($this);
    $this->putJson(DC_API."/breeders/{$case['breeder']}", ['code' => 'CH-001', 'name' => 'Azienda CH-001', 'jurisdiction' => 'CH', 'contact_id' => $contact->id])->assertOk();

    $data = $this->postJson(DC_API."/settlements/{$id}/document", ['document_type' => 'self_billing'])->assertOk()->json('data');

    expect($data['status'])->toBe('invoiced')->and($data['document_id'])->not->toBeNull();

    $invoice = PassiveInvoice::withoutGlobalScopes()->findOrFail($data['document_id']);
    expect($invoice->supplier_id)->toBe($contact->id)
        ->and($invoice->invoice_number)->toBe('AF-LATTE-2026-05-CH-001')
        ->and((string) $invoice->total_amount)->toBe('2462.30')
        ->and((string) $invoice->total_net)->toBe('2399.90')
        ->and($invoice->currency)->toBe('CHF')
        ->and(PassiveInvoiceLine::withoutGlobalScopes()->where('passive_invoice_id', $invoice->id)->count())->toBe(4);

    $this->postJson(DC_API."/settlements/{$id}/document", ['document_type' => 'invoice'])->assertUnprocessable()->assertJsonPath('code', 'settlement_not_approved');
});

it('keeps the grid read only once settlements use it and refuses to change a shared template', function (): void {
    $case = dcSettlementCase($this);
    $id = $this->postJson(DC_API.'/settlements/generate', ['period' => '2026-05', 'grid_id' => $case['grid']])->json('data.settlements.0.id');

    $this->deleteJson(DC_API."/pricing-grids/{$case['grid']}")->assertUnprocessable()->assertJsonPath('code', 'grid_in_use');

    $this->postJson(DC_API."/settlements/{$id}/approve")->assertOk();
    $this->putJson(DC_API."/pricing-grids/{$case['grid']}", ['name' => 'x', 'jurisdiction' => 'CH', 'base_price_per_kg' => 1, 'valid_from' => '2026-01-01'])->assertUnprocessable()->assertJsonPath('code', 'grid_in_use');

    $template = treasuryInTenant($this->tenant, fn () => PricingGrid::createGlobal([
        'name' => 'Template', 'jurisdiction' => 'CH', 'base_price_per_kg' => 0.5, 'valid_from' => '2026-01-01', 'is_active' => true, 'tiers' => [],
    ]));
    $this->getJson(DC_API.'/pricing-grids')->assertOk()->assertJsonFragment(['id' => $template->id, 'is_template' => true]);
    $this->putJson(DC_API."/pricing-grids/{$template->id}", ['name' => 'x', 'jurisdiction' => 'CH', 'base_price_per_kg' => 1, 'valid_from' => '2026-01-01'])->assertForbidden()->assertJsonPath('code', 'template_read_only');
});
