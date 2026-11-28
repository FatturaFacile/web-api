<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/BeekeepingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    beeSetUp($this);
    $this->origin = beeApiary($this);
    $this->pasture = beeApiary($this, ['code' => 'AP-002', 'name' => 'Pascolo Acacia', 'latitude' => 46.0, 'longitude' => 10.0, 'apiary_type' => 'nomadic']);
    foreach (['H-1', 'H-2', 'H-3'] as $code) {
        beeHive($this, $this->origin, $code);
    }
    beeHive($this, $this->origin, 'N-1', ['hive_type' => 'nucleus']);
    beeHive($this, $this->origin, 'H-dead', ['status' => 'dead']);
});

it('snapshots the productive hives and the nuclei per apiary in the census', function (): void {
    $census = $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.total_hives', 3)
        ->assertJsonPath('data.total_nuclei', 1)
        ->assertJsonPath('data.window_start', '2026-11-01')
        ->assertJsonPath('data.window_end', '2026-12-31')
        ->json('data');

    expect($census['snapshot'])->toHaveCount(1)->and($census['snapshot'][0]['code'])->toBe('AP-001')->and($census['snapshot'][0]['hives'])->toBe(3);
});

it('flags a census whose window is open today', function (): void {
    $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->assertJsonPath('data.in_window', false);

    $this->travelTo('2026-11-20 10:00:00');
    $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->assertJsonPath('data.in_window', true);
});

it('rebuilds a draft census instead of duplicating it and keeps countries apart', function (): void {
    $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->assertCreated();
    beeHive($this, $this->origin, 'H-4');
    $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->assertCreated()->assertJsonPath('data.total_hives', 4);
    $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'CH'])->assertCreated()->assertJsonPath('data.total_hives', 0);

    $this->getJson(BEE_API.'/census')->assertOk()->assertJsonCount(2, 'data');
});

it('freezes a submitted census', function (): void {
    $id = $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->json('data.id');

    $this->postJson(BEE_API."/census/{$id}/submit", ['submission_reference' => 'VETINFO-2026-55'])->assertOk()->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.submission_reference', 'VETINFO-2026-55');
    $this->postJson(BEE_API."/census/{$id}/submit", [])->assertUnprocessable()->assertJsonPath('code', 'census_submitted');
    $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->assertUnprocessable()->assertJsonPath('code', 'census_submitted');
});

it('exports the census as CSV, XML and PDF', function (): void {
    $id = $this->postJson(BEE_API.'/census/generate', ['census_year' => 2026, 'country' => 'IT'])->json('data.id');

    $csv = $this->get(BEE_API."/census/{$id}/export/csv");
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect((string) $csv->getContent())->toContain('apiary_code')->toContain('AP-001')->toContain('012BG123');

    $xml = simplexml_load_string((string) $this->get(BEE_API."/census/{$id}/export/xml")->assertOk()->getContent());
    expect((string) $xml['totalHives'])->toBe('3')->and((string) $xml->Apiary[0]->Hives)->toBe('3')->and((string) $xml->Apiary[0]->Nuclei)->toBe('1');

    $pdf = $this->get(BEE_API."/documents/census/{$id}?locale=it");
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('plans a nomadism movement with a numbered accompanying document', function (): void {
    $this->postJson(BEE_API.'/movements', [
        'kind' => 'nomadism', 'origin_apiary_id' => $this->origin->id, 'destination_apiary_id' => $this->pasture->id,
        'target_flora' => 'acacia', 'quantity' => 3, 'vehicle_plate' => 'ab123cd', 'departure_date' => '2026-04-20',
    ])->assertCreated()->assertJsonPath('data.document_number', 'MOV-2026-0001')->assertJsonPath('data.status', 'planned')->assertJsonPath('data.vehicle_plate', 'AB123CD');

    $second = $this->postJson(BEE_API.'/movements', ['kind' => 'nomadism', 'origin_apiary_id' => $this->origin->id, 'destination_label' => 'Valle Camonica', 'target_flora' => 'chestnut', 'quantity' => 1, 'departure_date' => '2026-06-20'])->assertCreated();
    expect($second->json('data.document_number'))->toBe('MOV-2026-0002');
});

it('validates the movements', function (array $payload, string $code): void {
    $base = ['kind' => 'nomadism', 'origin_apiary_id' => $this->origin->id, 'target_flora' => 'acacia', 'quantity' => 1, 'departure_date' => '2026-04-20', 'destination_apiary_id' => $this->pasture->id];

    $this->postJson(BEE_API.'/movements', array_merge($base, beeResolveIds($payload, $this->origin)))->assertUnprocessable()->assertJsonPath('code', $code);
})->with([
    'no destination' => [['destination_apiary_id' => null], 'movement_destination_required'],
    'no flora' => [['target_flora' => null], 'movement_flora_required'],
    'sale without counterpart' => [['kind' => 'sale', 'destination_apiary_id' => null], 'movement_counterpart_required'],
    'same apiary' => [['destination_apiary_id' => '@origin'], 'movement_same_apiary'],
    'more hives than the apiary holds' => [['quantity' => 50], 'movement_quantity_exceeds_hives'],
]);

it('moves a movement through planned, in transit and completed, and refuses a jump', function (): void {
    $id = $this->postJson(BEE_API.'/movements', ['kind' => 'sale', 'origin_apiary_id' => $this->origin->id, 'counterpart' => 'Apicoltore Verdi', 'subject' => 'nuclei', 'quantity' => 1, 'departure_date' => '2026-06-16'])->json('data.id');

    $this->postJson(BEE_API."/movements/{$id}/status", ['status' => 'completed'])->assertUnprocessable()->assertJsonPath('code', 'movement_invalid_transition');
    $this->postJson(BEE_API."/movements/{$id}/status", ['status' => 'in_transit'])->assertOk()->assertJsonPath('data.status', 'in_transit');
    $this->postJson(BEE_API."/movements/{$id}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('data.arrival_date', '2026-06-15');
    $this->postJson(BEE_API."/movements/{$id}/status", ['status' => 'cancelled'])->assertUnprocessable();
});

it('prints the accompanying document of a movement as a PDF', function (): void {
    $id = $this->postJson(BEE_API.'/movements', ['kind' => 'nomadism', 'origin_apiary_id' => $this->origin->id, 'destination_apiary_id' => $this->pasture->id, 'target_flora' => 'acacia', 'quantity' => 2, 'departure_date' => '2026-04-20'])->json('data.id');

    $pdf = $this->get(BEE_API."/documents/movements/{$id}?locale=fr");

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
});

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function beeResolveIds(array $payload, object $origin): array
{
    return array_map(static fn (mixed $value): mixed => $value === '@origin' ? $origin->id : $value, $payload);
}
