<?php

declare(strict_types=1);

require_once __DIR__.'/DairyCheeseSupport.php';

beforeEach(fn () => dcSetUp($this));

it('registers breeders with their CH and IT identifiers and keeps the code unique per company', function (): void {
    dcBreeder($this, 'CH-001');
    $this->postJson(DC_API.'/breeders', ['code' => 'IT-001', 'name' => 'Azienda Rossi', 'jurisdiction' => 'IT', 'cuaa' => 'CUAA001', 'asl_code' => 'ASL-12'])
        ->assertCreated()
        ->assertJsonPath('data.cuaa', 'CUAA001')
        ->assertJsonPath('data.jurisdiction', 'IT');

    $this->postJson(DC_API.'/breeders', ['code' => 'CH-001', 'name' => 'Doppione', 'jurisdiction' => 'CH'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->getJson(DC_API.'/breeders')->assertOk()->assertJsonCount(2, 'data');
});

it('updates a breeder without tripping over its own code and refuses an unknown jurisdiction', function (): void {
    $id = dcBreeder($this, 'CH-001');

    $this->putJson(DC_API."/breeders/{$id}", ['code' => 'CH-001', 'name' => 'Nuovo nome', 'jurisdiction' => 'CH'])->assertOk()->assertJsonPath('data.name', 'Nuovo nome');
    $this->putJson(DC_API."/breeders/{$id}", ['code' => 'CH-001', 'name' => 'x', 'jurisdiction' => 'FR'])->assertUnprocessable()->assertJsonValidationErrors('jurisdiction');
});

it('does not delete a breeder that has deliveries', function (): void {
    $breeder = dcBreeder($this);
    $empty = dcBreeder($this, 'CH-002');
    dcDelivery($this, $breeder, dcTank($this));

    $this->deleteJson(DC_API."/breeders/{$breeder}")->assertUnprocessable()->assertJsonPath('code', 'breeder_has_deliveries');
    $this->deleteJson(DC_API."/breeders/{$empty}")->assertOk();
});

it('isolates the data of the companies', function (): void {
    $id = dcBreeder($this);
    dcTank($this);

    treasuryActor($this, 'owner', $this->otherTenant);

    $this->getJson(DC_API.'/breeders')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(DC_API."/breeders/{$id}")->assertNotFound();
    $this->getJson(DC_API.'/tanks')->assertOk()->assertJsonCount(0, 'data');
});

it('lets a finance member read but not keep the milk records, and the other way round for settlements', function (): void {
    $breeder = dcBreeder($this);
    $tank = dcTank($this);

    treasuryActor($this, 'accountant');

    $this->getJson(DC_API.'/breeders')->assertOk();
    $this->postJson(DC_API.'/deliveries', ['breeder_id' => $breeder, 'tank_id' => $tank, 'collection_date' => '2026-05-10', 'session' => 'morning', 'liters' => 100])->assertForbidden();
    $this->postJson(DC_API.'/regulatory/generate', ['authority' => 'dbmilch', 'period' => '2026-05'])->assertForbidden();
    $this->postJson(DC_API.'/pricing-grids', ['name' => 'Griglia', 'jurisdiction' => 'CH', 'base_price_per_kg' => 0.7, 'valid_from' => '2026-01-01'])->assertCreated();
});

it('keeps a tank with its live volume and refuses to clean a tank that holds milk', function (): void {
    $tank = dcTank($this, 'T1', 5000);
    $this->postJson(DC_API."/tanks/{$tank}/clean")->assertOk()->assertJsonPath('data.status', 'active');

    dcDelivery($this, dcBreeder($this), $tank, liters: 1000, density: 1.03);

    $this->getJson(DC_API.'/tanks')->assertOk()->assertJsonPath('data.0.current_volume_liters', '1000.000')->assertJsonPath('data.0.current_weight_kg', '1030.000');
    $this->postJson(DC_API."/tanks/{$tank}/clean")->assertUnprocessable()->assertJsonPath('code', 'tank_not_empty');
});

it('reports the enumerations with the label keys of the frontend dictionary and the form lists', function (): void {
    dcBreeder($this);
    dcTank($this);
    dcRack($this);

    $data = $this->getJson(DC_API.'/lookups')->assertOk()->json('data');

    expect($data['enums']['authority'])->toBe([['value' => 'dbmilch', 'label_key' => 'DAIRY.ENUM.AUTHORITY.DBMILCH'], ['value' => 'sian', 'label_key' => 'DAIRY.ENUM.AUTHORITY.SIAN']])
        ->and(array_keys($data['enums']))->toContain('cheese_type', 'wheel_grade', 'care_type', 'pricing_component_type')
        ->and($data['cheese_types'])->toContain('gruyere_aop', 'parmigiano_reggiano_dop')
        ->and($data['breeders'])->toHaveCount(1)
        ->and($data['tanks'])->toHaveCount(1)
        ->and($data['racks'])->toHaveCount(1);
});

it('keeps the weight loss curves of the company and refuses to change a shared one', function (): void {
    $payload = ['cheese_type' => 'gruyere_aop', 'name' => 'Mia curva', 'points' => [['age_months' => 12, 'loss_pct' => 12], ['age_months' => 1, 'loss_pct' => 2]]];
    $id = $this->postJson(DC_API.'/weight-loss-curves', $payload)->assertCreated()->json('data.id');

    $this->getJson(DC_API.'/weight-loss-curves')->assertOk()->assertJsonPath('data.0.points.0.age_months', 1);
    $this->putJson(DC_API."/weight-loss-curves/{$id}", ['points' => [['age_months' => 6, 'loss_pct' => 5]]] + $payload)->assertOk()->assertJsonPath('data.points.0.loss_pct', 5);

    $this->postJson(DC_API.'/weight-loss-curves', ['points' => [['age_months' => 6, 'loss_pct' => 5], ['age_months' => 6, 'loss_pct' => 7]]] + $payload)->assertUnprocessable();
});
