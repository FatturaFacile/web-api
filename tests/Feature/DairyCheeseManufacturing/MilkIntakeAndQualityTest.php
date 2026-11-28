<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;

require_once __DIR__.'/DairyCheeseSupport.php';

beforeEach(fn () => dcSetUp($this));

it('converts the liters to kg with the density and defaults to 1.032', function (): void {
    $breeder = dcBreeder($this);
    $tank = dcTank($this);

    $explicit = dcDelivery($this, $breeder, $tank, liters: 1000, density: 1.03);
    $default = $this->postJson(DC_API.'/deliveries', ['breeder_id' => $breeder, 'tank_id' => $tank, 'collection_date' => '2026-05-11', 'session' => 'evening', 'liters' => 1000, 'temperature_c' => 4.2])
        ->assertCreated()->json('data');

    expect($explicit['kg'])->toBe('1030.000')->and($explicit['status'])->toBe('registered')->and($default['kg'])->toBe('1032.000')->and($default['density'])->toBe('1.0320');
});

it('refuses a delivery that does not fit in the tank and one from an inactive breeder', function (): void {
    $breeder = dcBreeder($this);
    $tank = dcTank($this, 'T1', 1500);
    dcDelivery($this, $breeder, $tank, liters: 1000);

    $this->postJson(DC_API.'/deliveries', ['breeder_id' => $breeder, 'tank_id' => $tank, 'collection_date' => '2026-05-10', 'session' => 'evening', 'liters' => 600])
        ->assertUnprocessable()->assertJsonPath('code', 'tank_capacity_exceeded');

    $inactive = dcBreeder($this, 'CH-009', ['is_active' => false]);
    $this->postJson(DC_API.'/deliveries', ['breeder_id' => $inactive, 'tank_id' => $tank, 'collection_date' => '2026-05-10', 'session' => 'evening', 'liters' => 100])
        ->assertUnprocessable()->assertJsonPath('code', 'breeder_inactive');
});

it('registers the rows of the quick entry grid one by one and reports the rejected ones', function (): void {
    $breeder = dcBreeder($this);
    $tank = dcTank($this, 'T1', 2500);

    $rows = [
        ['breeder_id' => $breeder, 'tank_id' => $tank, 'collection_date' => '2026-05-10', 'session' => 'morning', 'liters' => 1000],
        ['breeder_id' => $breeder, 'tank_id' => $tank, 'collection_date' => '2026-05-10', 'session' => 'evening', 'liters' => 2000],
        ['breeder_id' => $breeder, 'tank_id' => $tank, 'collection_date' => '2026-05-11', 'session' => 'morning', 'liters' => 1000],
    ];

    $data = $this->postJson(DC_API.'/deliveries/bulk', ['rows' => $rows])->assertCreated()->json('data');

    expect($data['created'])->toHaveCount(2)->and($data['rejected'])->toBe([['index' => 1, 'errors' => ['tank_capacity_exceeded']]]);
});

it('lists the intake ledger with its filters and pagination', function (): void {
    $a = dcBreeder($this, 'CH-001');
    $b = dcBreeder($this, 'CH-002');
    $tank = dcTank($this);
    dcDelivery($this, $a, $tank, '2026-05-10');
    dcDelivery($this, $b, $tank, '2026-05-11');
    dcDelivery($this, $b, $tank, '2026-05-12');

    $this->getJson(DC_API.'/deliveries?breeder_id='.$b)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $this->getJson(DC_API.'/deliveries?date_from=2026-05-11&date_to=2026-05-11')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(DC_API.'/deliveries?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
});

it('deletes a delivery that is neither settled nor used', function (): void {
    $id = dcDelivery($this, dcBreeder($this), dcTank($this))['id'];

    $this->deleteJson(DC_API."/deliveries/{$id}")->assertOk();
    $this->getJson(DC_API.'/deliveries')->assertOk()->assertJsonCount(0, 'data');
});

it('enters an analysis by hand and flags the anomalies', function (): void {
    $breeder = dcBreeder($this);

    $data = $this->postJson(DC_API.'/quality-tests', [
        'breeder_id' => $breeder, 'sample_date' => '2026-05-12', 'fat_pct' => 4.2, 'protein_pct' => 3.4, 'scc_cells_ml' => 450000, 'tbc_cfu_ml' => 20000, 'freezing_point_c' => -0.45, 'inhibitors' => 'negative',
    ])->assertCreated()->json('data');

    expect($data['flags'])->toBe(['scc_high', 'freezing_point_anomaly'])->and($data['period_start'])->toBe('2026-05-01')->and($data['period_end'])->toBe('2026-05-31');
});

it('quarantines the milk collected on the day of a positive inhibitor result and keeps it out of the vats until released', function (): void {
    $breeder = dcBreeder($this);
    $tank = dcTank($this);
    $delivery = dcDelivery($this, $breeder, $tank, '2026-05-20')['id'];

    $this->postJson(DC_API.'/quality-tests', ['breeder_id' => $breeder, 'sample_date' => '2026-05-20', 'inhibitors' => 'positive'])->assertCreated()->assertJsonPath('data.flags.0', 'inhibitor_positive');

    $this->getJson(DC_API.'/deliveries')->assertJsonPath('data.0.status', 'quarantined');
    $vat = ['cheese_type' => 'gruyere_aop', 'production_date' => '2026-05-21', 'tank_usages' => [['tank_id' => $tank, 'liters' => 500]]];
    $this->postJson(DC_API.'/vat-batches', $vat)->assertUnprocessable()->assertJsonPath('code', 'tank_contains_quarantined_milk');

    $this->postJson(DC_API."/deliveries/{$delivery}/release")->assertOk()->assertJsonPath('data.status', 'accepted');
    $this->postJson(DC_API.'/vat-batches', $vat)->assertCreated();
    $this->postJson(DC_API."/deliveries/{$delivery}/release")->assertUnprocessable()->assertJsonPath('code', 'delivery_not_quarantined');
});

it('discards a rejected delivery from the tank stock', function (): void {
    $tank = dcTank($this);
    $id = dcDelivery($this, dcBreeder($this), $tank)['id'];

    $this->postJson(DC_API."/deliveries/{$id}/reject")->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->getJson(DC_API.'/tanks')->assertJsonPath('data.0.current_volume_liters', '0.000');
});

it('previews a laboratory file with its anomalies and rejected rows, then commits only the valid ones', function (): void {
    $breeder = dcBreeder($this, 'CH-001');
    $tank = dcTank($this);
    dcDelivery($this, $breeder, $tank, '2026-05-20');

    $csv = "breeder_code,sample_date,fat_pct,protein_pct,scc_cells_ml,inhibitors\nCH-001,2026-05-12,4.10,3.40,150000,negative\nCH-001,2026-05-20,4.20,3.50,450000,positive\nXX-999,2026-05-12,4.0,3.3,100000,negative\n";
    $send = fn (bool $commit) => $this->post(DC_API.'/quality-tests/import', [
        'file' => UploadedFile::fake()->createWithContent('lab.csv', $csv), 'format' => 'generic_csv', 'commit' => $commit ? '1' : '0',
    ], ['Accept' => 'application/json']);

    $preview = $send(false)->assertOk()->json('data');

    expect($preview['committed'])->toBeFalse()
        ->and($preview['summary'])->toBe(['total' => 3, 'valid' => 2, 'anomalous' => 1, 'rejected' => 1])
        ->and($preview['rows'][1]['anomalies'])->toBe(['inhibitor_positive', 'scc_high'])
        ->and($preview['rows'][2]['errors'])->toBe(['breeder_not_found']);
    $this->getJson(DC_API.'/quality-tests')->assertJsonCount(0, 'data');

    $committed = $send(true)->assertOk()->json('data');

    expect($committed['committed'])->toBeTrue();
    $this->getJson(DC_API.'/quality-tests')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson(DC_API.'/deliveries')->assertJsonPath('data.0.status', 'quarantined');

    // The same file again: both rows are now duplicates.
    expect($send(false)->json('data.summary.rejected'))->toBe(3);
});

it('imports a Suisselab file by TVD number', function (): void {
    dcBreeder($this, 'CH-001', ['tvd_number' => 'CH123']);
    $csv = "TVD-Nr;Probedatum;Fett;Eiweiss;Keimzahl;Zellzahl;Gefrierpunkt;Hemmstoffe\nCH123;12.05.2026;4,10;3,40;12;180;-520;negativ\n";

    $data = $this->post(DC_API.'/quality-tests/import', [
        'file' => UploadedFile::fake()->createWithContent('suisselab.csv', $csv), 'format' => 'suisselab', 'commit' => '1',
    ], ['Accept' => 'application/json'])->assertOk()->json('data');

    expect($data['summary']['valid'])->toBe(1);
    $this->getJson(DC_API.'/quality-tests')->assertJsonPath('data.0.scc_cells_ml', 180000)->assertJsonPath('data.0.freezing_point_c', '-0.5200');
});

it('shows the intake of the day, the quality trend and the alerts on the dashboard', function (): void {
    $a = dcBreeder($this, 'CH-001');
    $b = dcBreeder($this, 'CH-002');
    $tank = dcTank($this);
    dcDelivery($this, $a, $tank, '2026-05-10');
    dcDelivery($this, $b, $tank, '2026-05-10', 'evening');
    $this->postJson(DC_API.'/quality-tests', ['breeder_id' => $a, 'sample_date' => '2026-05-10', 'fat_pct' => 4.0, 'protein_pct' => 3.2, 'scc_cells_ml' => 200000])->assertCreated();

    $kpis = $this->getJson(DC_API.'/dashboard?date=2026-05-10')->assertOk()->json('data.kpis');

    expect($kpis['milk_today_liters'])->toBe('2000.000')->and($kpis['deliveries_today'])->toBe(2)->and($kpis['avg_fat_pct'])->toBe('4.000')->and($kpis['avg_scc'])->toBe('200000');
    $this->getJson(DC_API.'/dashboard?date=2026-05-10')->assertJsonPath('data.trends.0.date', '2026-05-10')->assertJsonPath('data.trends.0.liters', '2000.000');
});
