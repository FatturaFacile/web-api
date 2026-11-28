<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Beekeeping\Events\ColonyAlertRaised;

require_once __DIR__.'/BeekeepingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    beeSetUp($this);
    $this->apiary = beeApiary($this);
    $this->hive = beeHive($this, $this->apiary, 'H-1', ['brood_frames' => 4, 'store_frames' => 3, 'supers_count' => 1]);
});

it('records an inspection and updates the colony figures of the hive', function (): void {
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", [
        'queen_seen' => true, 'eggs_present' => true, 'larvae_present' => true, 'capped_brood_present' => true,
        'brood_frames' => 6, 'store_frames' => 5, 'supers_added' => 1, 'supers_removed' => 0, 'strength_score' => 4,
        'weather' => 'sunny', 'temperature_c' => 22.5, 'feeding_type' => 'syrup', 'feeding_kg' => 2,
    ])->assertCreated()->assertJsonPath('data.alerts', []);

    $hive = $this->hive->fresh();
    expect($hive->brood_frames)->toBe(6)->and($hive->store_frames)->toBe(5)->and($hive->supers_count)->toBe(2)->and($hive->strength_score)->toBe(4);
    $this->getJson(BEE_API."/hives/{$this->hive->id}/inspections")->assertOk()->assertJsonCount(1, 'data');
});

it('raises the swarming alert when swarm cells are left alive', function (): void {
    Event::fake([ColonyAlertRaised::class]);

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['queen_seen' => true, 'swarm_cells_found' => 3, 'swarm_cells_destroyed' => 1])
        ->assertCreated()->assertJsonPath('data.alerts.0', 'swarming_risk');

    Event::assertDispatched(ColonyAlertRaised::class);
});

it('does not raise the swarming alert once every swarm cell was destroyed', function (): void {
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['queen_seen' => true, 'swarm_cells_found' => 2, 'swarm_cells_destroyed' => 2])
        ->assertCreated()->assertJsonPath('data.alerts', []);
});

it('raises the swarming alert on a crowded brood nest without supers', function (): void {
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['queen_seen' => true, 'brood_frames' => 9, 'supers_removed' => 1])
        ->assertCreated()->assertJsonPath('data.alerts.0', 'swarming_risk');
});

it('marks the hive orphaned when there is no queen, no eggs and no larvae and restores it when the queen is seen', function (): void {
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['queen_seen' => false, 'eggs_present' => false, 'larvae_present' => false])
        ->assertCreated()->assertJsonPath('data.alerts.0', 'queenless');

    expect($this->hive->fresh())->status->toBe('orphaned')->queen_status->toBe('missing');

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['queen_seen' => true, 'eggs_present' => true])->assertCreated();

    expect($this->hive->fresh())->status->toBe('active')->queen_status->toBe('present_laying');
});

it('validates the swarm cells and the feeding of an inspection', function (): void {
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['swarm_cells_found' => 1, 'swarm_cells_destroyed' => 2])->assertUnprocessable()->assertJsonValidationErrors('swarm_cells_destroyed');
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['feeding_type' => 'syrup'])->assertUnprocessable()->assertJsonValidationErrors('feeding_kg');
    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['inspected_at' => '2026-12-31 10:00:00'])->assertUnprocessable()->assertJsonValidationErrors('inspected_at');
});

it('inspects several hives of an apiary with the same observations', function (): void {
    $second = beeHive($this, $this->apiary, 'H-2');

    $this->postJson(BEE_API.'/inspections/batch', ['apiary_id' => $this->apiary->id, 'hive_ids' => [$this->hive->id, $second->id], 'queen_seen' => true, 'eggs_present' => true, 'brood_frames' => 5])
        ->assertCreated()->assertJsonCount(2, 'data');

    expect($this->hive->fresh()->brood_frames)->toBe(5)->and($second->fresh()->brood_frames)->toBe(5);
});

it('refuses a whole batch inspection when one hive is in withdrawal and adding supers', function (): void {
    $second = beeHive($this, $this->apiary, 'H-2');
    beeTreatment($this, $this->apiary, ['whole_apiary' => false, 'hive_ids' => [$second->id]]);

    $this->postJson(BEE_API.'/inspections/batch', ['apiary_id' => $this->apiary->id, 'hive_ids' => [$this->hive->id, $second->id], 'supers_added' => 1])->assertUnprocessable();

    expect($this->hive->fresh()->supers_count)->toBe(1);
});

it('needs the apiary and the hives for a batch inspection', function (): void {
    $this->postJson(BEE_API.'/inspections/batch', ['queen_seen' => true])->assertUnprocessable()->assertJsonPath('code', 'batch_inspection_incomplete');
});

it('keeps read-only users from recording inspections', function (): void {
    treasuryActor($this, 'accountant_readonly');

    $this->postJson(BEE_API."/hives/{$this->hive->id}/inspections", ['queen_seen' => true])->assertForbidden();
});
