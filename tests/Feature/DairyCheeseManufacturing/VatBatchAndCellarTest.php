<?php

declare(strict_types=1);

require_once __DIR__.'/DairyCheeseSupport.php';

beforeEach(fn () => dcSetUp($this));

it('draws the milk of a vat from the tank first-in first-out and keeps the composition by breeder', function (): void {
    $a = dcBreeder($this, 'CH-001');
    $b = dcBreeder($this, 'CH-002');
    $tank = dcTank($this);
    dcDelivery($this, $a, $tank, '2026-05-10', 'morning');
    dcDelivery($this, $b, $tank, '2026-05-10', 'evening');

    $data = $this->postJson(DC_API.'/vat-batches', [
        'cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-20', 'vat_number' => 'V1', 'tank_usages' => [['tank_id' => $tank, 'liters' => 1500]],
    ])->assertCreated()->json('data');

    expect($data['code'])->toBe('GR-20260520-01')
        ->and($data['status'])->toBe('open')
        ->and($data['milk_kg'])->toBe('1545.000')
        ->and($data['tank_usages'])->toHaveCount(1)
        ->and($data['composition'])->toHaveCount(2)
        ->and($data['composition'][0])->toMatchArray(['breeder_name' => 'Azienda CH-001', 'kg' => '1030.000', 'pct' => '66.667'])
        ->and($data['composition'][1])->toMatchArray(['breeder_name' => 'Azienda CH-002', 'kg' => '515.000', 'pct' => '33.333']);

    $this->getJson(DC_API.'/tanks')->assertJsonPath('data.0.current_volume_liters', '500.000')->assertJsonPath('data.0.current_weight_kg', '515.000');

    $second = $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-20', 'tank_usages' => [['tank_id' => $tank, 'liters' => 500]]])->assertCreated()->json('data');
    expect($second['code'])->toBe('GR-20260520-02')->and($second['composition'])->toHaveCount(1)->and($second['composition'][0]['breeder_name'])->toBe('Azienda CH-002');
});

it('refuses to draw more milk than the tank holds and a tank repeated in the same vat', function (): void {
    $tank = dcTank($this);
    dcDelivery($this, dcBreeder($this), $tank);

    $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'generic', 'production_date' => '2026-05-20', 'tank_usages' => [['tank_id' => $tank, 'liters' => 5000]]])
        ->assertUnprocessable()->assertJsonPath('code', 'insufficient_milk');
    $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'generic', 'production_date' => '2026-05-20', 'tank_usages' => [['tank_id' => $tank, 'liters' => 100], ['tank_id' => $tank, 'liters' => 100]]])
        ->assertUnprocessable()->assertJsonPath('code', 'tank_repeated');
    $this->getJson(DC_API.'/vat-batches')->assertOk()->assertJsonCount(0, 'data');
});

it('logs the process and completes the batch into one wheel per plaque with the 24 hour yield', function (): void {
    $c = dcCompletedBatch($this);

    $data = $this->getJson(DC_API."/vat-batches/{$c['batch']}")->assertOk()->json('data');

    expect($data['status'])->toBe('aging')
        ->and($data['wheels_count'])->toBe(3)
        ->and($data['fresh_yield_24h_kg'])->toBe('154.500')
        ->and($data['fresh_yield_24h_pct'])->toBe('10.000')
        ->and($data['plaque_prefix'])->toBe('GR26-')
        ->and($c['wheels'])->toHaveCount(3);

    $wheels = $this->getJson(DC_API.'/wheels?vat_batch_id='.$c['batch'])->assertOk()->json('data');
    expect(array_column($wheels, 'serial'))->toBe(['GR26-000001', 'GR26-000002', 'GR26-000003'])->and($wheels[0]['status'])->toBe('aging')->and($wheels[0]['position'])->toBe(1);

    $this->postJson(DC_API."/vat-batches/{$c['batch']}/complete", ['fresh_yield_24h_kg' => 1, 'plaque_from' => 10, 'plaque_to' => 10, 'initial_weight_kg' => 40])->assertUnprocessable()->assertJsonPath('code', 'batch_already_completed');
});

it('logs starter, rennet and the pH curve of the vat', function (): void {
    $tank = dcTank($this);
    dcDelivery($this, dcBreeder($this), $tank);
    $batch = $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-20', 'tank_usages' => [['tank_id' => $tank, 'liters' => 800]]])->json('data.id');

    $data = $this->putJson(DC_API."/vat-batches/{$batch}/process", [
        'starter_culture' => 'Siero innesto naturale', 'starter_amount' => 12.5, 'rennet_type' => 'Caglio di vitello', 'rennet_ml' => 80, 'curd_yield_kg' => 90,
        'ph_log' => [['at' => '2026-05-20T06:00:00Z', 'ph' => 6.62, 'temperature_c' => 32], ['at' => '2026-05-20T09:00:00Z', 'ph' => 6.45, 'temperature_c' => 54]],
    ])->assertOk()->json('data');

    expect($data['status'])->toBe('in_production')->and($data['ph_log'])->toHaveCount(2)->and($data['rennet_type'])->toBe('Caglio di vitello');
});

it('validates the plaque range and the wheel weights of a completion', function (): void {
    $tank = dcTank($this);
    dcDelivery($this, dcBreeder($this), $tank);
    $batch = $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'generic', 'production_date' => '2026-05-20', 'tank_usages' => [['tank_id' => $tank, 'liters' => 800]]])->json('data.id');

    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 80, 'plaque_from' => 5, 'plaque_to' => 2, 'initial_weight_kg' => 40])->assertUnprocessable()->assertJsonValidationErrors('plaque_to');
    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 80, 'plaque_from' => 1, 'plaque_to' => 3])->assertUnprocessable()->assertJsonValidationErrors('initial_weight_kg');
    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 80, 'plaque_from' => 1, 'plaque_to' => 3, 'wheels' => [['weight_kg' => 40]]])->assertUnprocessable()->assertJsonPath('code', 'wheels_count_mismatch');
    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 80, 'plaque_prefix' => 'G-', 'plaque_from' => 1, 'plaque_to' => 2, 'wheels' => [['weight_kg' => 39.5], ['weight_kg' => 40.5]]])->assertOk();

    $this->getJson(DC_API.'/wheels?search=g-000002')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.initial_weight_kg', '40.500');
});

it('refuses a plaque serial that is already assigned and a shelf that is full', function (): void {
    $c = dcCompletedBatch($this);
    $tank = $c['tank'];
    dcDelivery($this, $c['a'], $tank, '2026-05-21', 'morning');
    $batch = $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-22', 'tank_usages' => [['tank_id' => $tank, 'liters' => 500]]])->json('data.id');

    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 50, 'plaque_prefix' => 'GR26-', 'plaque_from' => 3, 'plaque_to' => 4, 'initial_weight_kg' => 50])
        ->assertUnprocessable()->assertJsonPath('code', 'plaque_serial_taken');

    $tight = dcRack($this, 1, 'A2');
    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 50, 'plaque_prefix' => 'GR27-', 'plaque_from' => 1, 'plaque_to' => 2, 'initial_weight_kg' => 50, 'rack_id' => $tight])
        ->assertUnprocessable()->assertJsonPath('code', 'rack_capacity_exceeded');
});

it('counts the wheels and their kilograms separately: the weight follows the loss curve while the count stays', function (): void {
    $c = dcCompletedBatch($this);

    $response = $this->getJson(DC_API.'/wheels')->assertOk();
    $wheel = $response->json('data.0');

    // 26 days = 0.85 months on a curve of 2 % at 1 month: 1.7 % lost, 50 kg -> 49.15 kg.
    expect($response->json('meta.totals'))->toBe(['wheels' => 3, 'initial_kg' => '150.000', 'current_kg' => '147.450', 'theoretical_kg' => '147.450', 'loss_pct' => '1.700'])
        ->and($wheel['age_months'])->toBe('0.85')
        ->and($wheel['theoretical_weight_loss_pct'])->toBe('1.700')
        ->and($wheel['current_weight_kg'])->toBe('49.150');

    $this->getJson(DC_API.'/wheels?age_min=1')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(DC_API.'/wheels?age_max=1&cheese_type=gruyere_aop')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(DC_API.'/wheels?cheese_type=sbrinz_aop')->assertOk()->assertJsonCount(0, 'data');
    expect($c['wheels'])->toHaveCount(3);
});

it('records a weighing, the consortium grade with fire-branding and the sale against the book weight', function (): void {
    $c = dcCompletedBatch($this);
    $wheel = $c['wheels'][0];

    $this->postJson(DC_API."/wheels/{$wheel}/weigh", ['weight_kg' => 48.5])->assertOk()->assertJsonPath('data.current_weight_kg', '48.500')->assertJsonPath('data.weight_loss_pct', '3.000');

    $this->postJson(DC_API."/wheels/{$wheel}/grade", ['grade' => 'discard', 'branded' => true])->assertUnprocessable()->assertJsonPath('code', 'branding_requires_grade');
    $this->postJson(DC_API."/wheels/{$wheel}/grade", ['grade' => 'first_category', 'branded' => true, 'notes' => 'Battitura ok'])
        ->assertOk()->assertJsonPath('data.grade', 'first_category')->assertJsonPath('data.branded', true);

    $sold = $this->postJson(DC_API.'/wheels/sell', ['wheel_ids' => [$wheel], 'customer_name' => 'Caseificio Rossi', 'weights' => [$wheel => 48.4], 'invoice_ref' => 'F-2026-1'])
        ->assertOk()->json('data');

    expect($sold['total_kg'])->toBe('48.400')
        ->and($sold['total_variance_kg'])->toBe('-0.100')
        ->and($sold['sold'][0])->toMatchArray(['status' => 'sold', 'sold_weight_kg' => '48.400', 'sale_variance_kg' => '-0.100', 'customer_name' => 'Caseificio Rossi']);

    $this->postJson(DC_API.'/wheels/sell', ['wheel_ids' => [$wheel], 'customer_name' => 'X', 'weights' => [$wheel => 48]])->assertUnprocessable()->assertJsonPath('code', 'wheel_not_in_cellar');
    $this->postJson(DC_API."/wheels/{$wheel}/weigh", ['weight_kg' => 48])->assertUnprocessable()->assertJsonPath('code', 'wheel_not_in_cellar');

    $kpis = $this->getJson(DC_API.'/dashboard')->assertOk()->json('data.kpis');
    expect($kpis['cellar_wheels'])->toBe(2)->and($kpis['cellar_current_kg'])->toBe('98.300')->and($kpis['cellar_capacity_wheels'])->toBe(10);

    // Sold weight 48.4 + two wheels still ageing at 49.15 kg each.
    $this->getJson(DC_API."/vat-batches/{$c['batch']}")->assertJsonPath('data.final_aged_yield_kg', '146.700');
});

it('requires the weighed weight of every wheel sold', function (): void {
    $c = dcCompletedBatch($this);

    $this->postJson(DC_API.'/wheels/sell', ['wheel_ids' => [$c['wheels'][0], $c['wheels'][1]], 'customer_name' => 'X', 'weights' => [$c['wheels'][0] => 49]])
        ->assertUnprocessable()->assertJsonPath('code', 'sale_weight_required');
    $this->getJson(DC_API.'/wheels?status=sold')->assertOk()->assertJsonCount(0, 'data');
});

it('moves a wheel between shelves within their capacity', function (): void {
    $c = dcCompletedBatch($this);
    $other = dcRack($this, 1, 'B1');

    $this->postJson(DC_API."/wheels/{$c['wheels'][0]}/move", ['rack_id' => $other, 'position' => 1])->assertOk()->assertJsonPath('data.shelf', 'B1');
    $this->postJson(DC_API."/wheels/{$c['wheels'][1]}/move", ['rack_id' => $other])->assertUnprocessable()->assertJsonPath('code', 'rack_capacity_exceeded');

    $map = $this->getJson(DC_API.'/racks')->assertOk()->json('data');
    $occupied = array_column($map['racks'], 'occupied_wheels', 'shelf');
    expect($occupied)->toBe(['A1' => 2, 'B1' => 1])->and($map['cells'])->toHaveCount(2);
});

it('schedules the care of a new batch, executes it for the whole batch and plans the next turning', function (): void {
    $c = dcCompletedBatch($this);

    $scheduled = $this->getJson(DC_API.'/care-logs?status=scheduled')->assertOk()->json('data');
    expect(array_column($scheduled, 'care_type'))->toBe(['brining', 'turning', 'brushing']);

    $this->getJson(DC_API.'/care-logs?overdue=1')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(DC_API.'/racks')->assertJsonPath('data.racks.0.overdue_care_count', 2)->assertJsonPath('data.cells.0.wheels.0.overdue', true);

    $done = $this->postJson(DC_API.'/care-logs/execute-batch', ['care_type' => 'turning', 'scope' => 'batch', 'target_id' => $c['batch'], 'performed_by' => 'Mario', 'notes' => 'Rivoltatura'])
        ->assertOk()->json('data');

    expect($done['status'])->toBe('done')->and($done['wheels_affected'])->toBe(3)->and($done['performed_by'])->toBe('Mario');

    $next = $this->getJson(DC_API.'/care-logs?status=scheduled&care_type=turning')->assertOk()->json('data');
    expect($next)->toHaveCount(1)->and($next[0]['scheduled_for'])->toBe('2026-06-29');
});

it('schedules and executes a care operation on a shelf and refuses an unknown target', function (): void {
    $c = dcCompletedBatch($this);

    $id = $this->postJson(DC_API.'/care-logs', ['care_type' => 'brushing', 'scope' => 'shelf', 'target_id' => $c['rack'], 'scheduled_for' => '2026-06-20'])
        ->assertCreated()->assertJsonPath('data.is_overdue', false)->json('data.id');

    $this->postJson(DC_API."/care-logs/{$id}/execute", [])->assertOk()->assertJsonPath('data.wheels_affected', 3);
    $this->postJson(DC_API."/care-logs/{$id}/execute", [])->assertUnprocessable()->assertJsonPath('code', 'care_already_closed');
    $this->postJson(DC_API.'/care-logs', ['care_type' => 'turning', 'scope' => 'batch', 'target_id' => '018f0000-0000-7000-8000-000000000000', 'scheduled_for' => '2026-06-20'])
        ->assertNotFound()->assertJsonPath('code', 'care_target_not_found');
    $this->postJson(DC_API.'/care-logs/execute-batch', ['care_type' => 'turning', 'scope' => 'wheel', 'target_id' => $c['wheels'][0]])->assertUnprocessable();
});
