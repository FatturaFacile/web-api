<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Beekeeping\Events\JarringRunRecorded;
use Modules\Beekeeping\Models\JarringRun;
use Modules\Beekeeping\Services\PackagingStock;

require_once __DIR__.'/BeekeepingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    beeSetUp($this);
    $this->apiary = beeApiary($this);
    $this->second = beeApiary($this, ['code' => 'AP-002', 'name' => 'Castagneto', 'latitude' => 46.1, 'longitude' => 9.9]);
    beeHive($this, $this->apiary, 'H-1');
    beeHive($this, $this->apiary, 'H-2');
});

function beeIntake(object $test, string $apiaryId, array $overrides = []): string
{
    return (string) $test->postJson(BEE_API.'/intakes', array_merge([
        'apiary_id' => $apiaryId, 'harvested_on' => '2026-05-20', 'botanical_origin' => 'acacia', 'supers_count' => 4, 'gross_weight_kg' => 100, 'net_weight_kg' => 60,
    ], $overrides))->assertCreated()->json('data.id');
}

function beeBatch(object $test, array $intakeIds, array $overrides = []): array
{
    return $test->postJson(BEE_API.'/batches', array_merge(['intake_ids' => $intakeIds, 'extracted_on' => '2026-06-01'], $overrides))->assertCreated()->json('data');
}

it('records a super intake with its gross and net weights', function (): void {
    $this->postJson(BEE_API.'/intakes', ['apiary_id' => $this->apiary->id, 'harvested_on' => '2026-05-20', 'botanical_origin' => 'acacia', 'supers_count' => 4, 'gross_weight_kg' => 50, 'net_weight_kg' => 60])
        ->assertUnprocessable()->assertJsonValidationErrors('net_weight_kg');

    beeIntake($this, $this->apiary->id);
    $this->getJson(BEE_API.'/intakes?pending=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.net_weight_kg', '60.00');
});

it('opens an extraction batch with an automatic HACCP lot code and the yield per hive', function (): void {
    $first = beeIntake($this, $this->apiary->id);
    $second = beeIntake($this, $this->apiary->id, ['harvested_on' => '2026-05-22', 'net_weight_kg' => 40]);

    $batch = beeBatch($this, [$first, $second], ['tank_code' => 'MAT-1']);

    expect($batch['lot_code'])->toBe('HNY-2026-ACA-001')->and($batch['extracted_kg'])->toBe('100.00')->and($batch['hives_harvested'])->toBe(2)->and($batch['yield_per_hive_kg'])->toBe(50.0)->and($batch['botanical_origin'])->toBe('acacia');

    $this->getJson(BEE_API.'/intakes?pending=1')->assertJsonCount(0, 'data');

    $next = beeBatch($this, [beeIntake($this, $this->apiary->id)]);
    expect($next['lot_code'])->toBe('HNY-2026-ACA-002');
});

it('refuses a super intake that already belongs to a batch and unknown intakes', function (): void {
    $intake = beeIntake($this, $this->apiary->id);
    beeBatch($this, [$intake]);

    $this->postJson(BEE_API.'/batches', ['intake_ids' => [$intake], 'extracted_on' => '2026-06-02'])->assertUnprocessable()->assertJsonPath('code', 'intake_already_extracted');
    $this->postJson(BEE_API.'/batches', ['intake_ids' => ['0198a3b0-0000-7000-8000-000000000000'], 'extracted_on' => '2026-06-02'])->assertUnprocessable();
});

it('asks for the origin of a batch made of supers with different botanical origins', function (): void {
    $acacia = beeIntake($this, $this->apiary->id);
    $chestnut = beeIntake($this, $this->second->id, ['botanical_origin' => 'chestnut']);

    $this->postJson(BEE_API.'/batches', ['intake_ids' => [$acacia, $chestnut], 'extracted_on' => '2026-06-01'])->assertUnprocessable()->assertJsonPath('code', 'intake_origin_mixed');

    $batch = beeBatch($this, [$acacia, $chestnut], ['botanical_origin' => 'wildflower']);
    expect($batch['lot_code'])->toBe('HNY-2026-WIL-001');
});

it('blocks the extraction when the apiary of a super is in withdrawal on the extraction date', function (): void {
    $intake = beeIntake($this, $this->apiary->id);
    beeTreatment($this, $this->apiary, ['treatment_date' => '2026-05-30']);

    $this->postJson(BEE_API.'/batches', ['intake_ids' => [$intake], 'extracted_on' => '2026-06-01'])->assertUnprocessable()->assertJsonPath('code', 'harvest_blocked_by_withdrawal');
});

it('warns about moisture and HMF against the statutory limits', function (float $moisture, float $hmf, array $warnings, bool $marketable): void {
    $batch = beeBatch($this, [beeIntake($this, $this->apiary->id)]);

    $this->putJson(BEE_API."/batches/{$batch['id']}/analysis", ['tank_code' => 'MAT-2', 'moisture_pct' => $moisture, 'hmf_mg_kg' => $hmf, 'decanted_on' => '2026-06-05'])
        ->assertOk()->assertJsonPath('data.quality_warnings', $warnings)->assertJsonPath('data.is_marketable', $marketable);
})->with([
    'within limits' => [16.5, 10.0, [], true],
    'moisture above 18' => [18.5, 10.0, ['moisture_warning'], true],
    'moisture above the 20 statutory limit' => [20.5, 10.0, ['moisture_critical'], false],
    'HMF approaching 40' => [16.5, 35.0, ['hmf_warning'], true],
    'HMF above 40' => [16.5, 41.0, ['hmf_critical'], false],
    'both critical' => [21.0, 50.0, ['moisture_critical', 'hmf_critical'], false],
]);

it('jars the honey with a lot code per run, the TMC and the origin country', function (): void {
    Event::fake([JarringRunRecorded::class]);
    $batch = beeBatch($this, [beeIntake($this, $this->apiary->id)]);

    $run = $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 500, 'jars_count' => 40])
        ->assertCreated()->assertJsonPath('data.jar_lot_code', $batch['lot_code'].'-500-01')->assertJsonPath('data.net_kg', '20.00')->assertJsonPath('data.best_before', '2028-06-10')->assertJsonPath('data.origin_country', 'IT')
        ->json('data');

    Event::assertDispatched(JarringRunRecorded::class);
    $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 1000, 'jars_count' => 10])->assertCreated()->assertJsonPath('data.jar_lot_code', $batch['lot_code'].'-1000-02');
    $this->getJson(BEE_API."/batches/{$batch['id']}")->assertOk()->assertJsonPath('data.jarred_kg', 30.0);

    $label = $this->get(BEE_API."/documents/jarring-runs/{$run['id']}/label?locale=it");
    $label->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $label->getContent(), 0, 4))->toBe('%PDF');
});

it('refuses to jar more honey than the batch holds and honey above a statutory limit', function (): void {
    $batch = beeBatch($this, [beeIntake($this, $this->apiary->id)]); // 60 kg

    $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 1000, 'jars_count' => 61])->assertUnprocessable()->assertJsonPath('code', 'batch_exhausted')->assertJsonPath('context.remaining', '60.00');
    $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 750, 'jars_count' => 1])->assertUnprocessable()->assertJsonValidationErrors('jar_format_g');

    $this->putJson(BEE_API."/batches/{$batch['id']}/analysis", ['moisture_pct' => 21])->assertOk();
    $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 500, 'jars_count' => 1])->assertUnprocessable()->assertJsonPath('code', 'batch_not_marketable');
});

it('deducts jars, caps and labels from the warehouse through the packaging stock consumer', function (): void {
    $consumer = Mockery::mock(PackagingStock::class);
    $consumer->shouldReceive('consume')->once()->withArgs(fn (JarringRun $run): bool => $run->jars_count === 12)->andReturn(['movement-1', 'movement-2', 'movement-3']);
    app()->instance(PackagingStock::class, $consumer);

    $batch = beeBatch($this, [beeIntake($this, $this->apiary->id)]);
    $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 250, 'jars_count' => 12])
        ->assertCreated()->assertJsonPath('data.stock_movements', ['movement-1', 'movement-2', 'movement-3']);
});

it('traces a jar lot back to the batch, the apiaries and the last treatment before the harvest', function (): void {
    beeTreatment($this, $this->apiary, ['treatment_date' => '2026-03-01', 'product' => 'amitraz']); // ends 2026-03-31, before the harvest
    $batch = beeBatch($this, [beeIntake($this, $this->apiary->id)], ['tank_code' => 'MAT-9']);
    $this->putJson(BEE_API."/batches/{$batch['id']}/analysis", ['moisture_pct' => 18.4, 'hmf_mg_kg' => 12])->assertOk();
    $run = $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 500, 'jars_count' => 20])->json('data');

    $trace = $this->getJson(BEE_API.'/traceability/backward/'.$run['jar_lot_code'])->assertOk()->json('data');

    expect($trace['direction'])->toBe('backward')->and($trace['jar_lot']['jars_count'])->toBe(20)->and($trace['batch']['lot_code'])->toBe($batch['lot_code'])->and($trace['batch']['tank_code'])->toBe('MAT-9')
        ->and($trace['batch']['quality_warnings'])->toBe(['moisture_warning'])
        ->and($trace['batch']['intakes'][0]['apiary']['code'])->toBe('AP-001')
        ->and($trace['batch']['intakes'][0]['last_treatments'][0]['product'])->toBe('amitraz')
        ->and($trace['batch']['intakes'][0]['last_treatments'][0]['withdrawal_respected'])->toBeTrue();

    $this->getJson(BEE_API.'/traceability/backward/NOPE')->assertNotFound()->assertJsonPath('code', 'jar_lot_not_found');
});

it('traces an apiary, a batch and an intake forward to the finished jar lots', function (): void {
    $intakeId = beeIntake($this, $this->apiary->id);
    $batch = beeBatch($this, [$intakeId]);
    $run = $this->postJson(BEE_API."/batches/{$batch['id']}/jarring", ['jarred_on' => '2026-06-10', 'jar_format_g' => 500, 'jars_count' => 10])->json('data');

    foreach ([['apiary', $this->apiary->id], ['batch', $batch['id']], ['intake', $intakeId]] as [$type, $id]) {
        $trace = $this->getJson(BEE_API."/traceability/forward?type={$type}&id={$id}")->assertOk()->json('data');
        expect($trace['direction'])->toBe('forward')->and($trace['batches'][0]['jar_lots'][0]['jar_lot_code'])->toBe($run['jar_lot_code']);
    }

    $this->getJson(BEE_API.'/traceability/forward?type=apiary')->assertUnprocessable()->assertJsonPath('code', 'trace_reference_required');
    $this->getJson(BEE_API."/traceability/forward?type=apiary&id={$this->second->id}")->assertOk()->assertJsonCount(0, 'data.batches');
});

it('prints the HACCP sheet of a batch as a PDF', function (): void {
    $batch = beeBatch($this, [beeIntake($this, $this->apiary->id)]);

    $pdf = $this->get(BEE_API."/documents/batches/{$batch['id']}/haccp?locale=en");

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});
