<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Facility\Jobs\GenerateFacilityInspectionsJob;
use Modules\Facility\Models\FacilityInspection;
use Modules\Facility\Models\FacilityInspectionTemplate;
use Modules\Facility\Models\FacilityMeter;
use Modules\Facility\Models\FacilityServiceRequest;
use Modules\Facility\Services\InspectionService;

require_once __DIR__.'/FacilityTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-05-12 08:00:00');
    $this->tenant = facTenant();
    $this->manager = facMember($this->tenant, 'facility_manager');
    $this->technician = facMember($this->tenant, 'technician');
    $this->member = facMember($this->tenant, 'member');
    $this->building = facBuilding($this->tenant);
    facAct($this->manager, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @return array<string, mixed>
 */
function facTemplatePayload(string $buildingId, array $extra = []): array
{
    return [
        'name' => 'Estintori',
        'building_id' => $buildingId,
        'frequency' => 'weekly',
        'next_due_date' => '2027-05-12',
        'checklist' => [
            ['label' => 'Sigillo integro', 'type' => 'pass_fail', 'required' => true],
            ['label' => 'Pressione (bar)', 'type' => 'number', 'required' => false],
            ['label' => 'Sigillo integro'],
        ],
        ...$extra,
    ];
}

test('a template generates one inspection per due date, once only, and moves on to the next date', function (): void {
    $template = $this->postJson('/api/v1/facility/inspection-templates', facTemplatePayload($this->building->id))->assertCreated()->json('data');
    expect(array_column($template['checklist'], 'key'))->toBe(['sigillo_integro', 'pressione_bar', 'sigillo_integro_2']);

    $created = $this->postJson('/api/v1/facility/inspection-templates/generate')->assertOk()->assertJsonCount(1, 'data')->json('data');
    expect($created[0]['number'])->toBe('INS-2027-0001')
        ->and($created[0]['due_date'])->toBe('2027-05-12')
        ->and($created[0]['status'])->toBe('scheduled')
        ->and($created[0]['checklist'][0]['result'])->toBeNull();

    $this->getJson("/api/v1/facility/inspection-templates/{$template['id']}")->assertOk()->assertJsonPath('data.next_due_date', '2027-05-19');
    $this->postJson('/api/v1/facility/inspection-templates/generate')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson('/api/v1/facility/inspection-templates/generate', ['as_of' => '2027-06-02'])->assertOk()->assertJsonCount(3, 'data');
    expect(FacilityInspection::query()->count())->toBe(4)
        ->and(FacilityInspectionTemplate::query()->first()->next_due_date->toDateString())->toBe('2027-06-09');

    $this->putJson("/api/v1/facility/inspection-templates/{$template['id']}", facTemplatePayload($this->building->id, ['checklist' => [['label' => 'Solo una voce']], 'next_due_date' => '2027-06-09']))->assertOk();
    expect(FacilityInspection::query()->first()->checklist)->toHaveCount(3);
});

test('the lead days bring the inspection forward and the scheduler job is idempotent', function (): void {
    $this->postJson('/api/v1/facility/inspection-templates', facTemplatePayload($this->building->id, ['frequency' => 'monthly', 'next_due_date' => '2027-05-15', 'lead_days' => 3]))->assertCreated();
    $this->postJson('/api/v1/facility/inspection-templates', facTemplatePayload($this->building->id, ['name' => 'Più avanti', 'next_due_date' => '2027-05-20', 'lead_days' => 3]))->assertCreated();

    (new GenerateFacilityInspectionsJob($this->tenant->id))->handle(app(TenantContext::class));
    (new GenerateFacilityInspectionsJob($this->tenant->id))->handle(app(TenantContext::class));

    $inspections = FacilityInspection::query()->get();
    expect($inspections)->toHaveCount(1)
        ->and($inspections[0]->due_date->toDateString())->toBe('2027-05-15')
        ->and(FacilityInspectionTemplate::query()->where('name', 'Estintori')->first()->next_due_date->toDateString())->toBe('2027-06-15');
});

test('monthly recurrences advance without overflow to the last day of shorter months', function (): void {
    $service = app(InspectionService::class);

    expect($service->advance(CarbonImmutable::parse('2027-01-31'), 'monthly', 1)->toDateString())->toBe('2027-02-28')
        ->and($service->advance(CarbonImmutable::parse('2027-11-30'), 'quarterly', 1)->toDateString())->toBe('2028-02-29')
        ->and($service->advance(CarbonImmutable::parse('2028-02-29'), 'yearly', 1)->toDateString())->toBe('2029-02-28')
        ->and($service->advance(CarbonImmutable::parse('2027-05-12'), 'daily', 3)->toDateString())->toBe('2027-05-15');
});

test('completing an inspection records the answers and a failed item raises a service request', function (): void {
    $this->postJson('/api/v1/facility/inspection-templates', facTemplatePayload($this->building->id))->assertCreated();
    $inspection = $this->postJson('/api/v1/facility/inspection-templates/generate')->assertOk()->json('data.0');

    facAct($this->member, $this->tenant);
    $this->getJson('/api/v1/facility/inspections')->assertForbidden();
    $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/complete", ['answers' => [['key' => 'sigillo_integro', 'result' => 'pass']]])->assertForbidden();

    facAct($this->technician, $this->tenant);
    $this->getJson('/api/v1/facility/inspections?open=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/start")->assertOk()->assertJsonPath('data.status', 'in_progress');
    $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/start")->assertUnprocessable();

    $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/complete", ['answers' => [['key' => 'pressione_bar', 'value' => 12]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['answers']);
    $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/complete", ['answers' => [['key' => 'sconosciuta', 'result' => 'pass']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['answers']);

    $done = $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/complete", [
        'answers' => [
            ['key' => 'sigillo_integro', 'result' => 'fail', 'notes' => 'Sigillo rotto'],
            ['key' => 'pressione_bar', 'value' => 12.5],
            ['key' => 'sigillo_integro_2', 'result' => 'pass'],
        ],
        'notes' => 'Corridoio nord',
    ])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.result', 'fail')->json('data');

    expect($done['checklist'][1]['value'])->toBe('12.5')->and($done['service_request_id'])->not->toBeNull();

    $request = FacilityServiceRequest::query()->findOrFail($done['service_request_id']);
    expect($request->category)->toBe('repair')
        ->and($request->priority)->toBe('high')
        ->and($request->inspection_id)->toBe($inspection['id'])
        ->and($request->building_id)->toBe($this->building->id)
        ->and($request->title)->toContain('INS-2027-0001');

    $this->postJson("/api/v1/facility/inspections/{$inspection['id']}/cancel", ['reason' => 'No'])->assertUnprocessable();
});

test('a passing inspection raises nothing and an inspection can be cancelled', function (): void {
    $this->postJson('/api/v1/facility/inspection-templates', facTemplatePayload($this->building->id))->assertCreated();
    $this->postJson('/api/v1/facility/inspection-templates/generate', ['as_of' => '2027-05-19'])->assertOk()->assertJsonCount(2, 'data');
    $first = FacilityInspection::query()->orderBy('due_date')->get();

    $this->postJson("/api/v1/facility/inspections/{$first[0]->id}/complete", ['answers' => [['key' => 'sigillo_integro', 'result' => 'pass'], ['key' => 'sigillo_integro_2', 'result' => 'na']]])
        ->assertOk()->assertJsonPath('data.result', 'pass')->assertJsonPath('data.service_request_id', null);
    $this->postJson("/api/v1/facility/inspections/{$first[1]->id}/cancel", ['reason' => 'Edificio chiuso'])->assertOk()->assertJsonPath('data.status', 'cancelled');

    expect(FacilityServiceRequest::query()->count())->toBe(0);
    $this->getJson('/api/v1/facility/inspections?status=cancelled')->assertOk()->assertJsonCount(1, 'data');
});

/**
 * @return array<string, mixed>
 */
function facMeterPayload(string $buildingId, array $extra = []): array
{
    return ['building_id' => $buildingId, 'kind' => 'electricity', 'code' => 'EL-01', 'name' => 'Contatore generale', 'unit' => 'kWh', 'tariff_per_unit' => 0.25, 'currency' => 'EUR', ...$extra];
}

test('readings give the consumption since the previous one costed with the tariff', function (): void {
    $meter = $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id))->assertCreated()->json('data');
    $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id, ['unit' => 'm3', 'code' => 'EL-02']))->assertUnprocessable()->assertJsonValidationErrors(['unit']);
    $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id))->assertUnprocessable()->assertJsonValidationErrors(['code']);

    $baseline = $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-04-30', 'value' => 1000])->assertCreated()->json('data');
    expect($baseline['consumption'])->toBe('0.000')->and($baseline['cost'])->toBe('0.00');

    $reading = $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-10', 'value' => '1250.500'])->assertCreated()->json('data');
    expect($reading['consumption'])->toBe('250.500')->and($reading['cost'])->toBe('62.63')->and($reading['currency'])->toBe('EUR');

    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-11', 'value' => 1200])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-10', 'value' => 1300])->assertUnprocessable()->assertJsonValidationErrors(['read_on']);
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-13', 'value' => 1300])->assertUnprocessable()->assertJsonValidationErrors(['read_on']);

    $this->getJson("/api/v1/facility/meters/{$meter['id']}")->assertOk()->assertJsonPath('data.last_value', '1250.500')->assertJsonPath('data.last_read_on', '2027-05-10');
    $this->putJson("/api/v1/facility/meters/{$meter['id']}", facMeterPayload($this->building->id, ['unit' => 'MWh']))->assertUnprocessable()->assertJsonValidationErrors(['unit']);
});

test('a roll-over or a replacement of the meter is declared and counted without a negative consumption', function (): void {
    $meter = $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id, ['code' => 'GAS-01', 'kind' => 'gas', 'unit' => 'Sm3', 'rollover_value' => 99999, 'tariff_per_unit' => null]))->assertCreated()->json('data');
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-04-01', 'value' => 99990])->assertCreated();

    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-01', 'value' => 15, 'reading_type' => 'rollover'])
        ->assertCreated()->assertJsonPath('data.consumption', '24.000')->assertJsonPath('data.cost', null);
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-02', 'value' => 20, 'reading_type' => 'rollover'])
        ->assertUnprocessable()->assertJsonValidationErrors(['value']);
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-03', 'value' => 120, 'reading_type' => 'replacement'])
        ->assertCreated()->assertJsonPath('data.consumption', '120.000');

    $plain = $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id, ['code' => 'EL-09']))->assertCreated()->json('data');
    $this->postJson("/api/v1/facility/meters/{$plain['id']}/readings", ['read_on' => '2027-04-01', 'value' => 500])->assertCreated();
    $this->postJson("/api/v1/facility/meters/{$plain['id']}/readings", ['read_on' => '2027-05-01', 'value' => 10, 'reading_type' => 'rollover'])
        ->assertUnprocessable()->assertJsonValidationErrors(['reading_type']);
});

test('the monthly summary adds consumption and cost per month, kind and unit and the latest reading can be removed', function (): void {
    $power = $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id))->assertCreated()->json('data');
    $water = $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id, ['code' => 'WA-01', 'kind' => 'water', 'unit' => 'm3', 'tariff_per_unit' => 2.5, 'currency' => 'CHF']))->assertCreated()->json('data');

    foreach ([['2027-03-31', 1000], ['2027-04-30', 1400], ['2027-05-10', 1650.5]] as [$date, $value]) {
        $this->postJson("/api/v1/facility/meters/{$power['id']}/readings", ['read_on' => $date, 'value' => $value])->assertCreated();
    }
    foreach ([['2027-03-31', 50], ['2027-04-30', 62.4]] as [$date, $value]) {
        $this->postJson("/api/v1/facility/meters/{$water['id']}/readings", ['read_on' => $date, 'value' => $value])->assertCreated();
    }

    $summary = $this->getJson('/api/v1/facility/meters/summary?year=2027')->assertOk()->json('data');
    $rows = collect($summary['rows'])->keyBy(fn (array $row): string => $row['month'].'|'.$row['kind']);

    expect($rows['2027-04|electricity']['consumption'])->toBe('400.000')
        ->and($rows['2027-04|electricity']['cost'])->toBe('100.00')
        ->and($rows['2027-05|electricity']['consumption'])->toBe('250.500')
        ->and($rows['2027-05|electricity']['cost'])->toBe('62.63')
        ->and($rows['2027-04|water']['consumption'])->toBe('12.400')
        ->and($rows['2027-04|water']['currency'])->toBe('CHF')
        ->and($rows['2027-04|water']['cost'])->toBe('31.00');

    $totals = collect($summary['totals'])->keyBy('kind');
    expect($totals['electricity']['consumption'])->toBe('650.500')->and($totals['electricity']['cost'])->toBe('162.63');

    $this->getJson('/api/v1/facility/meters/summary?year=2027&kind=water')->assertOk()->assertJsonCount(1, 'data.totals');
    $this->getJson('/api/v1/facility/meters/summary')->assertUnprocessable();

    $readings = $this->getJson("/api/v1/facility/meters/{$power['id']}/readings")->assertOk()->json('data');
    $this->deleteJson("/api/v1/facility/meters/{$power['id']}/readings/{$readings[1]['id']}")->assertUnprocessable();
    $this->deleteJson("/api/v1/facility/meters/{$power['id']}/readings/{$readings[0]['id']}")->assertNoContent();
    $this->getJson("/api/v1/facility/meters/{$power['id']}")->assertOk()->assertJsonPath('data.last_value', '1400.000')->assertJsonPath('data.last_read_on', '2027-04-30');

    expect(FacilityMeter::query()->count())->toBe(2);
});

test('meters and inspections are for operators and the register for managers', function (): void {
    $meter = $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id))->assertCreated()->json('data');

    facAct($this->member, $this->tenant);
    $this->getJson('/api/v1/facility/meters')->assertForbidden();
    $this->getJson("/api/v1/facility/meters/{$meter['id']}")->assertForbidden();
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-10', 'value' => 10])->assertForbidden();
    $this->getJson('/api/v1/facility/inspection-templates')->assertForbidden();

    facAct($this->technician, $this->tenant);
    $this->getJson('/api/v1/facility/meters')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/v1/facility/meters/{$meter['id']}/readings", ['read_on' => '2027-05-10', 'value' => 10])->assertCreated();
    $this->postJson('/api/v1/facility/meters', facMeterPayload($this->building->id, ['code' => 'EL-77']))->assertForbidden();
    $this->postJson('/api/v1/facility/inspection-templates', facTemplatePayload($this->building->id))->assertForbidden();
    $this->deleteJson("/api/v1/facility/meters/{$meter['id']}")->assertForbidden();
});
