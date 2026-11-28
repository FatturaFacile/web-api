<?php

declare(strict_types=1);

require_once __DIR__.'/DairyCheeseSupport.php';

beforeEach(fn () => dcSetUp($this));

it('validates a period before it is filed: an open month, a month without movements and breeders without registry numbers', function (): void {
    $breeder = dcBreeder($this, 'CH-001', ['bdm_number' => null, 'tvd_number' => null]);
    dcDelivery($this, $breeder, dcTank($this), '2026-06-10');

    $open = $this->postJson(DC_API.'/regulatory/preview', ['authority' => 'dbmilch', 'period' => '2026-06'])->assertOk()->json('data');
    expect($open['valid'])->toBeFalse()->and($open['errors'])->toContain('period_open', 'breeder_bdm_missing:CH-001');

    $empty = $this->postJson(DC_API.'/regulatory/preview', ['authority' => 'sian', 'period' => '2026-05'])->assertOk()->json('data');
    expect($empty['valid'])->toBeFalse()->and($empty['errors'])->toContain('no_movements');

    $this->postJson(DC_API.'/regulatory/preview', ['authority' => 'sian', 'period' => '2026-5'])->assertUnprocessable();
});

it('previews the DBMilch.ch movements: the deliveries of the Swiss producers and the milk processed into cheese', function (): void {
    $c = dcCompletedBatch($this);
    expect($c['a'])->not->toBe('');

    $data = $this->postJson(DC_API.'/regulatory/preview', ['authority' => 'dbmilch', 'period' => '2026-05'])->assertOk()->json('data');

    expect($data['valid'])->toBeTrue()
        ->and($data['warnings'])->toContain('quality_test_missing:CH-001')
        ->and(array_count_values(array_column($data['movements'], 'type')))->toBe(['milk_intake' => 2, 'milk_transformed' => 1])
        ->and($data['totals'])->toMatchArray(['producers' => 2, 'deliveries' => 2, 'milk_delivered_kg' => '2060.000', 'cheese_milk_kg' => '1545.000']);
});

it('generates the DBMilch.ch XML, keeps it as an audit record and downloads it', function (): void {
    dcCompletedBatch($this);

    $submission = $this->postJson(DC_API.'/regulatory/generate', ['authority' => 'dbmilch', 'period' => '2026-05'])->assertCreated()->json('data');

    expect($submission)->toMatchArray(['authority' => 'dbmilch', 'status' => 'generated', 'format' => 'xml', 'record_count' => 3, 'file_name' => 'dbmilch_tsm_2026-05.xml'])
        ->and($submission['checksum'])->toHaveLength(64);

    $content = $this->get(DC_API."/regulatory/submissions/{$submission['id']}/download")->assertOk()->streamedContent();
    expect(simplexml_load_string($content))->not->toBeFalse()
        ->and($content)->toContain('<TSMMilchmeldung')->toContain('bdm="BDM-CH-001"')->toContain('milch_geliefert_kg="2060.000"')->toContain('verkaeste_milch_kg="1545.000"')
        ->and(hash('sha256', $content))->toBe($submission['checksum']);

    $this->getJson(DC_API.'/regulatory/submissions?authority=dbmilch')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson(DC_API.'/regulatory/submissions?authority=sian')->assertOk()->assertJsonCount(0, 'data');
});

it('generates the SIAN register movements as CSV and records their submission', function (): void {
    $breeder = dcBreeder($this, 'IT-001', ['jurisdiction' => 'IT', 'bdm_number' => null, 'tvd_number' => null, 'cuaa' => 'CUAA001', 'asl_code' => 'ASL-12']);
    $tank = dcTank($this);
    dcDelivery($this, $breeder, $tank, '2026-05-10');
    $batch = $this->postJson(DC_API.'/vat-batches', ['cheese_type' => 'parmigiano_reggiano_dop', 'production_date' => '2026-05-12', 'tank_usages' => [['tank_id' => $tank, 'liters' => 500]]])->json('data.id');
    $this->postJson(DC_API."/vat-batches/{$batch}/complete", ['fresh_yield_24h_kg' => 40, 'plaque_prefix' => 'PR-', 'plaque_from' => 1, 'plaque_to' => 1, 'initial_weight_kg' => 40])->assertOk();

    $submission = $this->postJson(DC_API.'/regulatory/generate', ['authority' => 'sian', 'period' => '2026-05'])->assertCreated()->json('data');

    expect($submission)->toMatchArray(['status' => 'generated', 'format' => 'csv', 'record_count' => 3, 'file_name' => 'sian_registro_latte_2026-05.csv']);

    $content = $this->get(DC_API."/regulatory/submissions/{$submission['id']}/download")->assertOk()->streamedContent();
    expect($content)->toContain('CARICO;2026-05-10')->toContain('LAVORAZIONE;2026-05-12')->toContain('PRODUZIONE;2026-05-12')->toContain('CUAA001');

    $xml = $this->postJson(DC_API.'/regulatory/generate', ['authority' => 'sian', 'period' => '2026-05', 'format' => 'xml'])->assertCreated()->json('data.id');
    expect($this->get(DC_API."/regulatory/submissions/{$xml}/download")->streamedContent())->toContain('<RegistroTelematicoLatte')->toContain('D.M. 27/05/2021')->toContain('tipo="CARICO"');

    $this->postJson(DC_API."/regulatory/submissions/{$submission['id']}/mark-submitted")->assertOk()->assertJsonPath('data.status', 'submitted');
    $this->postJson(DC_API."/regulatory/submissions/{$submission['id']}/mark-submitted")->assertUnprocessable()->assertJsonPath('code', 'submission_not_generated');
});

it('keeps a declaration that failed validation as a failed record without a file', function (): void {
    $failed = $this->postJson(DC_API.'/regulatory/generate', ['authority' => 'dbmilch', 'period' => '2026-06'])->assertCreated()->json('data');

    expect($failed['status'])->toBe('failed')->and($failed['validation_errors'])->toContain('period_open');
    $this->get(DC_API."/regulatory/submissions/{$failed['id']}/download", ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('code', 'submission_without_file');
});

it('shows the open filing deadlines on the dashboard', function (): void {
    $deadlines = $this->getJson(DC_API.'/dashboard')->assertOk()->json('data.filing_deadlines');

    expect($deadlines)->not->toBeEmpty()
        ->and(array_column($deadlines, 'authority'))->toContain('dbmilch', 'sian')
        ->and($deadlines[0]['status'])->toBe('pending');
});

it('traces a wheel back to every contributing farmer, delivery, tank and vat', function (): void {
    $c = dcCompletedBatch($this);

    $trace = $this->getJson(DC_API.'/traceability?direction=backward&type=wheel&serial=GR26-000001')->assertOk()->json('data');

    expect($trace['root'])->toMatchArray(['type' => 'wheel', 'label' => 'GR26-000001'])
        ->and($trace['root']['children'][0]['type'])->toBe('batch')
        ->and($trace['root']['children'][0]['children'][0]['type'])->toBe('tank')
        ->and($trace['summary'])->toMatchArray(['farmers' => 2, 'deliveries' => 2, 'tanks' => 1, 'batches' => 1, 'wheels' => 1]);

    $byId = $this->getJson(DC_API.'/traceability?direction=backward&type=batch&id='.$c['batch'])->assertOk()->json('data.summary');
    expect($byId['farmers'])->toBe(2);
});

it('traces a delivery forward to the wheels and customers that received its milk', function (): void {
    $c = dcCompletedBatch($this);
    $this->postJson(DC_API.'/wheels/sell', ['wheel_ids' => [$c['wheels'][0]], 'customer_name' => 'Caseificio Rossi', 'weights' => [$c['wheels'][0] => 49], 'invoice_ref' => 'F1'])->assertOk();

    $trace = $this->getJson(DC_API.'/traceability?direction=forward&type=delivery&id='.$c['delivery_a'])->assertOk()->json('data');

    expect($trace['summary'])->toMatchArray(['deliveries' => 1, 'tanks' => 1, 'batches' => 1, 'wheels' => 3, 'customers' => 1]);

    $this->getJson(DC_API.'/traceability?direction=forward&type=delivery&id=018f0000-0000-7000-8000-000000000000')->assertNotFound()->assertJsonPath('code', 'trace_not_found');
    $this->getJson(DC_API.'/traceability?direction=forward&type=wheel&serial=NOPE')->assertNotFound();
    $this->getJson(DC_API.'/traceability?direction=forward&type=wheel')->assertUnprocessable();
});

it('traces an analysis forward through the deliveries of its period', function (): void {
    $c = dcCompletedBatch($this);
    $test = $this->postJson(DC_API.'/quality-tests', ['breeder_id' => $c['a'], 'sample_date' => '2026-05-10', 'inhibitors' => 'negative'])->json('data.id');

    $summary = $this->getJson(DC_API.'/traceability?direction=forward&type=test&id='.$test)->assertOk()->json('data.summary');

    expect($summary)->toMatchArray(['deliveries' => 1, 'batches' => 1, 'wheels' => 3]);
});

it('generates the recall report of a contaminated delivery and puts the affected wheels on recall', function (): void {
    $c = dcCompletedBatch($this);
    $this->postJson(DC_API.'/wheels/sell', ['wheel_ids' => [$c['wheels'][0]], 'customer_name' => 'Caseificio Rossi', 'weights' => [$c['wheels'][0] => 49]])->assertOk();

    $payload = ['type' => 'delivery', 'id' => $c['delivery_a'], 'reason' => 'Antibiotici positivi'];
    $report = $this->postJson(DC_API.'/traceability/recall-report', $payload)->assertOk()->json('data');

    expect($report['applied'])->toBeFalse()
        ->and($report['affected_wheels'])->toHaveCount(3)
        ->and($report['affected_batches'])->toHaveCount(1)
        ->and($report['customers'])->toBe([['name' => 'Caseificio Rossi', 'wheels' => 1, 'kg' => '49.000']])
        ->and($report['summary']['wheels'])->toBe(3);
    $this->getJson(DC_API."/wheels/{$c['wheels'][1]}")->assertJsonPath('data.status', 'aging');

    $applied = $this->postJson(DC_API.'/traceability/recall-report', $payload + ['apply' => true])->assertOk()->json('data');

    expect($applied['applied'])->toBeTrue();
    $this->getJson(DC_API."/wheels/{$c['wheels'][1]}")->assertJsonPath('data.status', 'recalled');
    $this->getJson(DC_API.'/wheels?status=recalled')->assertOk()->assertJsonCount(3, 'data');
    $this->postJson(DC_API."/wheels/{$c['wheels'][1]}/weigh", ['weight_kg' => 49])->assertUnprocessable()->assertJsonPath('code', 'wheel_not_in_cellar');
});

it('reserves the recall and the declarations to the people in charge of quality', function (): void {
    $c = dcCompletedBatch($this);

    treasuryActor($this, 'accountant');

    $this->postJson(DC_API.'/traceability/recall-report', ['type' => 'delivery', 'id' => $c['delivery_a'], 'reason' => 'x'])->assertForbidden();
    $this->getJson(DC_API.'/traceability?direction=forward&type=delivery&id='.$c['delivery_a'])->assertOk();
});
