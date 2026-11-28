<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Models\BondedMrnLot;
use Modules\BondedWarehouses\Models\CguLedgerEntry;
use Modules\BondedWarehouses\Models\CustomsDischargeMovement;

require_once __DIR__.'/BondedWarehousesSupport.php';

uses(RefreshDatabase::class);

const BW_DISCHARGE = BW_API.'/mrn-lots/discharge';

test('a FIFO discharge for free circulation takes the oldest lots first and makes the duty and VAT due', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $older = bwLot($this, $authorization, 1, ['entry_date' => '2027-04-01']);
    $newer = bwLot($this, $authorization, 2, ['entry_date' => '2027-05-01', 'customs_statistical_value' => 2000]);

    $response = $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 150))->assertCreated();

    expect($response->json('data.summary.total_quantity'))->toBe(150.0)
        ->and($response->json('data.summary.customs_debt_arises'))->toBeTrue()
        ->and($response->json('data.lines.0.mrn_number'))->toBe(bwMrn(1))
        ->and($response->json('data.lines.0.quantity'))->toBe(100.0)
        ->and($response->json('data.lines.1.mrn_number'))->toBe(bwMrn(2))
        ->and($response->json('data.lines.1.quantity'))->toBe(50.0)
        ->and($response->json('data.summary.total_customs_value'))->toBe(2000.0)
        ->and($response->json('data.summary.total_duty_due'))->toBe(200.0)
        ->and($response->json('data.summary.total_vat_due'))->toBe(484.0)
        ->and($response->json('data.summary.total_cgu_released'))->toBe(684.0);

    $older->refresh();
    $newer->refresh();

    expect($older->remaining_quantity)->toBe('0.0000')
        ->and($older->customs_status->value)->toBe('FullyDischarged')
        ->and($older->cgu_engaged_amount)->toBe('0.00')
        ->and($newer->remaining_quantity)->toBe('50.0000')
        ->and($newer->remaining_net_weight_kg)->toBe('50.000')
        ->and($newer->customs_status->value)->toBe('PartiallyDischarged')
        ->and($newer->cgu_engaged_amount)->toBe('342.00')
        ->and($authorization->refresh()->cgu_utilized_amount)->toBe('342.00');

    $movement = CustomsDischargeMovement::query()->where('mrn_lot_id', $newer->id)->sole();

    expect($movement->paid_duty_amount)->toBe('100.00')
        ->and($movement->paid_vat_amount)->toBe('242.00')
        ->and($movement->extinguished_duty_amount)->toBe('0.00')
        ->and(CguLedgerEntry::query()->where('movement_direction', 'Release')->count())->toBe(2);
});

test('a Specific MRN discharge uses exactly the lots named', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1, ['entry_date' => '2027-04-01']);
    $newer = bwLot($this, $authorization, 2, ['entry_date' => '2027-05-01']);

    $payload = bwFifoDischarge($authorization, $this, 0, [
        'allocation_method' => 'Specific_MRN', 'product_id' => null, 'quantity' => null,
        'allocations' => [['mrn_lot_id' => $newer->id, 'quantity' => 40]],
    ]);

    $this->postJson(BW_DISCHARGE, $payload)->assertCreated()->assertJsonCount(1, 'data.lines')->assertJsonPath('data.lines.0.mrn_number', bwMrn(2));

    expect($newer->refresh()->remaining_quantity)->toBe('60.0000')
        ->and(BondedMrnLot::query()->where('mrn_number', bwMrn(1))->sole()->remaining_quantity)->toBe('100.0000');
});

test('a re-export, an export and a transit extinguish the duty and VAT instead of making them due', function (string $type): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);

    $response = $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 100, ['discharge_type' => $type]))->assertCreated();

    expect($response->json('data.summary.customs_debt_arises'))->toBeFalse()
        ->and($response->json('data.summary.total_duty_due'))->toBe(0.0)
        ->and($response->json('data.summary.total_duty_extinguished'))->toBe(100.0)
        ->and($response->json('data.summary.total_vat_extinguished'))->toBe(242.0)
        ->and($response->json('data.summary.total_cgu_released'))->toBe(342.0)
        ->and($authorization->refresh()->cgu_utilized_amount)->toBe('0.00');
})->with(['EX3_ReExport', 'Passar_Export', 'T1_Transit']);

test('a usual handling creates no customs debt, keeps the guarantee and the MRN lineage', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $lot = bwLot($this, $authorization, 1);

    $payload = bwFifoDischarge($authorization, $this, 100, [
        'discharge_type' => 'UsualHandling_Annex71_03', 'discharge_mrn_number' => null, 'usual_handling_code' => 'Repacking', 'usual_handling_notes' => 'Retail bags',
    ]);

    $this->postJson(BW_DISCHARGE, $payload)->assertCreated()->assertJsonPath('data.summary.customs_debt_arises', false)->assertJsonPath('data.summary.total_cgu_released', 0.0);

    $lot->refresh();

    expect($lot->customs_status->value)->toBe('UsualHandling_InProgress')
        ->and($lot->remaining_quantity)->toBe('100.0000')
        ->and($lot->cgu_engaged_amount)->toBe('342.00')
        ->and($authorization->refresh()->cgu_utilized_amount)->toBe('342.00')
        ->and(CustomsDischargeMovement::query()->where('mrn_lot_id', $lot->id)->sole()->usual_handling_code)->toBe('Repacking');

    $this->postJson(BW_DISCHARGE, array_merge($payload, ['usual_handling_code' => null]))->assertStatus(422)->assertJsonValidationErrors('usual_handling_code');
});

test('a discharge beyond the remaining quantity or weight is refused', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $lot = bwLot($this, $authorization, 1);

    $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 101))->assertStatus(422)->assertJsonPath('code', 'insufficient_remaining_quantity');

    $specific = bwFifoDischarge($authorization, $this, 0, [
        'allocation_method' => 'Specific_MRN', 'product_id' => null, 'quantity' => null,
        'allocations' => [['mrn_lot_id' => $lot->id, 'quantity' => 10, 'net_weight_kg' => 500]],
    ]);
    $this->postJson(BW_DISCHARGE, $specific)->assertStatus(422)->assertJsonPath('code', 'insufficient_remaining_weight');

    expect(CustomsDischargeMovement::query()->count())->toBe(0);
});

test('a discharge dated before the entry is refused', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1, ['entry_date' => '2027-06-15']);

    $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 10, ['discharge_date' => '2027-06-01']))->assertStatus(422)->assertJsonPath('code', 'discharge_before_entry');
});

test('the simulation calculates the plan and writes nothing', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $lot = bwLot($this, $authorization, 1);

    $payload = bwFifoDischarge($authorization, $this, 50, ['discharge_mrn_number' => null]);
    $this->postJson(BW_DISCHARGE.'/simulate', $payload)->assertOk()
        ->assertJsonPath('data.simulated', true)
        ->assertJsonPath('data.summary.total_duty_due', 50.0)
        ->assertJsonPath('data.summary.total_vat_due', 121.0)
        ->assertJsonPath('data.movement_ids', []);

    expect($lot->refresh()->remaining_quantity)->toBe('100.0000')
        ->and(CustomsDischargeMovement::query()->count())->toBe(0)
        ->and($authorization->refresh()->cgu_utilized_amount)->toBe('342.00');
});

test('successive discharges of a lot add up to exactly the suspended amounts', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $lot = bwLot($this, $authorization, 1, ['initial_quantity' => 3, 'initial_net_weight_kg' => 3, 'customs_statistical_value' => 100, 'duty_rate_percent' => 0, 'vat_rate_percent' => 0]);

    foreach ([1, 1, 1] as $step => $quantity) {
        $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, $quantity, ['discharge_mrn_number' => bwMrn(900 + $step)]))->assertCreated();
    }

    $values = CustomsDischargeMovement::query()->where('mrn_lot_id', $lot->id)->orderBy('created_at')->pluck('released_customs_value')->map(fn (string $value): string => $value)->all();

    expect(round(array_sum(array_map('floatval', $values)), 2))->toBe(100.0)
        ->and($lot->refresh()->customs_status->value)->toBe('FullyDischarged')
        ->and($lot->remaining_net_weight_kg)->toBe('0.000');
});

test('the discharge trail and the CGU ledger cannot be changed or deleted', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    bwLot($this, $authorization, 1);
    $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 10))->assertCreated();

    $movement = CustomsDischargeMovement::query()->firstOrFail();
    $entry = CguLedgerEntry::query()->firstOrFail();

    expect(fn () => $movement->update(['discharged_quantity' => 1]))->toThrow(BondedWarehouseException::class)
        ->and(fn () => $movement->delete())->toThrow(BondedWarehouseException::class)
        ->and(fn () => $entry->update(['duty_amount' => 1]))->toThrow(BondedWarehouseException::class)
        ->and(fn () => $entry->delete())->toThrow(BondedWarehouseException::class);
});

test('the lot drill-down lists every movement and the registers export as CSV', function (): void {
    bwSetUp($this);
    $authorization = bwAuthorization($this);
    $lot = bwLot($this, $authorization, 1);
    $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 10))->assertCreated();
    $this->postJson(BW_DISCHARGE, bwFifoDischarge($authorization, $this, 10, ['discharge_type' => 'EX3_ReExport', 'discharge_mrn_number' => bwMrn(901)]))->assertCreated();

    $this->getJson(BW_API.'/mrn-lots/'.$lot->id)->assertOk()->assertJsonCount(2, 'data.discharges')->assertJsonPath('data.discharges.0.discharge_type', 'IM4_FreeCirculation');

    $csv = $this->get(BW_API.'/exports/mrn-lots.csv')->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')->and($csv->getContent())->toContain(bwMrn(1));
    $this->get(BW_API.'/exports/cgu-ledger.csv')->assertOk();
    $this->get(BW_API.'/exports/unknown.csv')->assertNotFound();
});
