<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\OliveMillAndGrainMill\Models\SianMovement;

require_once __DIR__.'/OliveMillSupport.php';

uses(RefreshDatabase::class);

test('a tank is registered with its capacity in litres and its place on the map', function (): void {
    omSetUp($this);

    $this->postJson(OM_API.'/sian-tanks', ['code' => 'S1', 'description' => 'Inox 5 t', 'material' => 'INOX_AISI_316', 'capacity_kg' => 5000, 'grid_row' => 2, 'grid_col' => 3, 'zone_label' => 'Cantina'])
        ->assertCreated()
        ->assertJsonPath('data.capacity_l', '5458.515')
        ->assertJsonPath('data.stock_kg', '0.000')
        ->assertJsonPath('data.category', null)
        ->assertJsonPath('data.fill_percent', 0.0)
        ->assertJsonPath('data.grid_row', 2)
        ->assertJsonPath('data.zone_label', 'Cantina');

    $this->getJson(OM_API.'/sian-tanks')->assertOk()->assertJsonCount(1, 'data');
});

test('the tank code is unique inside the company', function (): void {
    omSetUp($this);
    omTank($this, ['code' => 'S1']);

    $this->postJson(OM_API.'/sian-tanks', ['code' => 'S1', 'capacity_kg' => 1000])->assertUnprocessable()->assertJsonValidationErrors('code');

    $other = omTank($this, ['code' => 'S2']);
    $this->putJson(OM_API.'/sian-tanks/'.$other->id, ['code' => 'S2', 'capacity_kg' => 2000])->assertOk()->assertJsonPath('data.capacity_kg', '2000.000');
});

test('the capacity cannot go below the stock and a tank in use cannot be removed', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    omStock($this, $tank, 800);

    $this->putJson(OM_API.'/sian-tanks/'.$tank->id, ['code' => 'S1', 'capacity_kg' => 500])->assertUnprocessable()->assertJsonPath('code', 'capacity_below_stock');
    $this->putJson(OM_API.'/sian-tanks/'.$tank->id, ['code' => 'S1', 'capacity_kg' => 5000, 'is_active' => false])->assertUnprocessable()->assertJsonPath('code', 'tank_in_use');
    $this->deleteJson(OM_API.'/sian-tanks/'.$tank->id)->assertUnprocessable()->assertJsonPath('code', 'tank_in_use');
});

test('an unused tank can be deleted', function (): void {
    omSetUp($this);
    $tank = omTank($this);

    $this->deleteJson(OM_API.'/sian-tanks/'.$tank->id)->assertOk();
    $this->getJson(OM_API.'/sian-tanks')->assertOk()->assertJsonCount(0, 'data');
});

test('a transfer moves the oil with its lots and writes a T01 operation', function (): void {
    omSetUp($this);
    $from = omTank($this, ['code' => 'S1']);
    $to = omTank($this, ['code' => 'S2']);
    omStock($this, $from, 1000);

    $response = $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $from->id, 'to_tank_id' => $to->id, 'quantity_kg' => 400, 'notes' => 'for bottling'])
        ->assertCreated()
        ->assertJsonPath('data.downgraded', false)
        ->assertJsonPath('data.from.stock_kg', '600.000')
        ->assertJsonPath('data.to.stock_kg', '400.000')
        ->assertJsonPath('data.to.category', 'EVO')
        ->assertJsonPath('data.movement.causal', 'T01');

    expect($to->refresh()->lot_composition)->toEqual(['2027-OL-00001' => 400])
        ->and($from->refresh()->lot_composition)->toEqual(['2027-OL-00001' => 600])
        ->and($response->json('data.movement.status'))->toBe('DRAFT');

    $movement = SianMovement::query()->where('causal', 'T01')->firstOrFail();

    expect($movement->from_tank_id)->toBe($from->id)->and($movement->to_tank_id)->toBe($to->id)->and($movement->quantity_kg)->toBe('400.000');
});

test('blending extra virgin oil into virgin oil downgrades the tank', function (): void {
    omSetUp($this);
    $evo = omTank($this, ['code' => 'S1']);
    $virgin = omTank($this, ['code' => 'S2']);
    omStock($this, $evo, 500, 'EVO');
    omStock($this, $virgin, 300, 'VERGINE', [], ['acidity_pct' => 1.2]);

    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $evo->id, 'to_tank_id' => $virgin->id, 'quantity_kg' => 100])
        ->assertCreated()
        ->assertJsonPath('data.downgraded', true)
        ->assertJsonPath('data.to.category', 'VERGINE')
        ->assertJsonPath('data.to.stock_kg', '400.000')
        ->assertJsonPath('data.movement.category', 'VERGINE');

    expect($evo->refresh()->category?->value)->toBe('EVO')->and($virgin->refresh()->lot_composition)->toHaveCount(2);
});

test('emptying a tank clears its category and lots', function (): void {
    omSetUp($this);
    $from = omTank($this, ['code' => 'S1']);
    $to = omTank($this, ['code' => 'S2']);
    omStock($this, $from, 300);

    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $from->id, 'to_tank_id' => $to->id, 'quantity_kg' => 300])->assertCreated();

    $from->refresh();
    expect($from->stock_kg)->toBe('0.000')->and($from->category)->toBeNull()->and($from->origin_designation)->toBeNull()->and($from->lot_composition)->toBe([]);
});

test('protected and organic oils are never blended into another category', function (): void {
    omSetUp($this);
    $dop = omTank($this, ['code' => 'S1']);
    $evo = omTank($this, ['code' => 'S2']);
    $bio = omTank($this, ['code' => 'S3']);
    omStock($this, $dop, 300, 'DOP');
    omStock($this, $evo, 300, 'EVO');
    omStock($this, $bio, 300, 'BIOLOGICO');

    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $dop->id, 'to_tank_id' => $evo->id, 'quantity_kg' => 50])->assertUnprocessable()->assertJsonPath('code', 'dop_igp_mixing');
    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $evo->id, 'to_tank_id' => $bio->id, 'quantity_kg' => 50])->assertUnprocessable()->assertJsonPath('code', 'bio_segregation');

    expect($dop->refresh()->stock_kg)->toBe('300.000')->and(SianMovement::query()->where('causal', 'T01')->count())->toBe(0);
});

test('a transfer respects the capacity, the stock and the two distinct tanks', function (): void {
    omSetUp($this);
    $from = omTank($this, ['code' => 'S1']);
    $small = omTank($this, ['code' => 'S2', 'capacity_kg' => 100]);
    $empty = omTank($this, ['code' => 'S3']);
    omStock($this, $from, 500);

    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $from->id, 'to_tank_id' => $small->id, 'quantity_kg' => 200])->assertUnprocessable()->assertJsonPath('code', 'tank_capacity_exceeded');
    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $from->id, 'to_tank_id' => $empty->id, 'quantity_kg' => 900])->assertUnprocessable()->assertJsonPath('code', 'insufficient_tank_stock');
    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $empty->id, 'to_tank_id' => $from->id, 'quantity_kg' => 10])->assertUnprocessable()->assertJsonPath('code', 'tank_empty');
    $this->postJson(OM_API.'/sian-tanks/transfer', ['from_tank_id' => $from->id, 'to_tank_id' => $from->id, 'quantity_kg' => 10])->assertUnprocessable()->assertJsonValidationErrors('to_tank_id');

    expect($from->refresh()->stock_kg)->toBe('500.000');
});

test('the bottling unloads the tank, generates the lot and the best before date', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    omStock($this, $tank, 1000);

    $this->postJson(OM_API.'/sian-tanks/package', ['tank_id' => $tank->id, 'bottle_format' => '0.50L', 'bottle_count' => 200])
        ->assertCreated()
        ->assertJsonPath('data.movement.causal', 'S03')
        ->assertJsonPath('data.movement.bottle_format', '0.50L')
        ->assertJsonPath('data.movement.bottle_count', 200)
        ->assertJsonPath('data.movement.quantity_kg', '91.600')
        ->assertJsonPath('data.movement.quantity_l', '100.000')
        ->assertJsonPath('data.movement.package_lot', 'L271110-S1-01')
        ->assertJsonPath('data.movement.best_before', '2029-05-10')
        ->assertJsonPath('data.tank.stock_kg', '908.400');

    $this->postJson(OM_API.'/sian-tanks/package', ['tank_id' => $tank->id, 'bottle_format' => '5L', 'bottle_count' => 10])
        ->assertCreated()
        ->assertJsonPath('data.movement.package_lot', 'L271110-S1-02')
        ->assertJsonPath('data.movement.quantity_l', '50.000');
});

test('the bottling needs the oil in the tank', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);

    $this->postJson(OM_API.'/sian-tanks/package', ['tank_id' => $tank->id, 'bottle_format' => '0.75L', 'bottle_count' => 10])->assertUnprocessable()->assertJsonPath('code', 'tank_empty');

    omStock($this, $tank, 50);

    $this->postJson(OM_API.'/sian-tanks/package', ['tank_id' => $tank->id, 'bottle_format' => '5L', 'bottle_count' => 100])->assertUnprocessable()->assertJsonPath('code', 'insufficient_tank_stock');
    $this->postJson(OM_API.'/sian-tanks/package', ['tank_id' => $tank->id, 'bottle_format' => '1L', 'bottle_count' => 1])->assertUnprocessable()->assertJsonValidationErrors('bottle_format');
});

test('a rectification reduces the stock with an R01 operation and needs a reason', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    omStock($this, $tank, 500);

    $this->postJson(OM_API.'/sian-tanks/adjust', ['tank_id' => $tank->id, 'quantity_kg' => 12.5])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->postJson(OM_API.'/sian-tanks/adjust', ['tank_id' => $tank->id, 'quantity_kg' => 12.5, 'reason' => 'Natural loss'])
        ->assertCreated()
        ->assertJsonPath('data.movement.causal', 'R01')
        ->assertJsonPath('data.movement.notes', 'Natural loss')
        ->assertJsonPath('data.tank.stock_kg', '487.500');

    $this->postJson(OM_API.'/sian-tanks/adjust', ['tank_id' => $tank->id, 'quantity_kg' => 600, 'reason' => 'Count'])->assertUnprocessable()->assertJsonPath('code', 'insufficient_tank_stock');
});

test('an inactive tank cannot receive oil', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);
    $this->putJson(OM_API.'/sian-tanks/'.$tank->id, ['code' => 'S1', 'capacity_kg' => 5000, 'is_active' => false])->assertOk();

    $cycle = omStartCycle($this, omDelivery($this));

    omComplete($this, $cycle, ['pickup_kg' => 500, 'mill_stock_kg' => 100, 'target_tank_id' => $tank->id])->assertUnprocessable()->assertJsonPath('code', 'tank_inactive');
});

test('the tanks of another company are neither listed nor reachable', function (): void {
    omSetUp($this);
    $tank = omTank($this, ['code' => 'S1']);

    treasuryActor($this, 'accountant', $this->otherTenant);

    $this->getJson(OM_API.'/sian-tanks')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(OM_API.'/sian-tanks/'.$tank->id)->assertNotFound();
    $this->postJson(OM_API.'/sian-tanks/adjust', ['tank_id' => $tank->id, 'quantity_kg' => 1, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('tank_id');
});
