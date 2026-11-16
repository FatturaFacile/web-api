<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\Sustainability\Models\EsgSupplierDataRequest;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = Tenant::create(['name' => 'Esrs '.Str::random(4), 'legal_name' => 'Esrs Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $this->owner = esrsMember($this->tenant, 'owner');
    esrsAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function esrsMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@esrs-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function esrsAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @return array<string, mixed>
 */
function esrsPeriod(): array
{
    return test()->postJson('/api/v1/sustainability/periods', ['name' => 'FY 2027', 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'revenue' => 2000000, 'employees_fte' => 20])->assertCreated()->json('data');
}

/**
 * Creates and approves an assessment where only the listed standards are material.
 *
 * @param  list<string>  $material
 */
function esrsAssessment(string $periodId, array $material): string
{
    $topics = [];
    foreach (['E1', 'E2', 'E3', 'E4', 'E5', 'S1', 'S2', 'S3', 'S4', 'G1'] as $code) {
        $isMaterial = in_array($code, $material, true);
        $topics[] = ['esrs_code' => $code, 'name' => "Topic {$code}", 'impact_score' => $isMaterial ? '4' : '1', 'financial_score' => $isMaterial ? '4' : '1'];
    }

    $id = test()->postJson('/api/v1/sustainability/materiality-assessments', ['name' => 'DMA 2027', 'reporting_period_id' => $periodId, 'topics' => $topics])->assertCreated()->json('data.id');
    test()->postJson("/api/v1/sustainability/materiality-assessments/{$id}/approve")->assertOk();

    return $id;
}

it('installs the ESRS catalog once and lets the company add data points', function (): void {
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk()->assertJsonPath('created', 41);
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk()->assertJsonPath('created', 0);

    $standards = collect($this->getJson('/api/v1/sustainability/esrs/datapoints')->assertOk()->json('data'))->pluck('standard')->unique()->sort()->values()->all();
    expect($standards)->toBe(['E1', 'E2', 'E3', 'E4', 'E5', 'ESRS2', 'G1', 'S1', 'S2', 'S3', 'S4']);
    $this->getJson('/api/v1/sustainability/esrs/datapoints?standard=G1')->assertOk()->assertJsonCount(3, 'data');

    $created = $this->postJson('/api/v1/sustainability/esrs/datapoints', ['code' => 'E1-CUSTOM', 'standard' => 'E1', 'disclosure' => 'E1-6', 'name' => 'Custom', 'data_type' => 'number', 'unit' => 'tCO2e'])->assertCreated()->json('data');
    $this->postJson('/api/v1/sustainability/esrs/datapoints', ['code' => 'E1-CUSTOM', 'standard' => 'E1', 'disclosure' => 'E1-6', 'name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->putJson("/api/v1/sustainability/esrs/datapoints/{$created['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson('/api/v1/sustainability/esrs/datapoints', ['code' => 'X', 'standard' => 'Z9', 'disclosure' => 'x', 'name' => 'Bad'])->assertUnprocessable()->assertJsonValidationErrors('standard');
});

it('makes the double materiality assessment decide which standards are required', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk();

    $withoutAssessment = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs/completeness")->assertOk()->json('data');
    expect($withoutAssessment['issues'])->toContain('materiality_assessment_missing')
        ->and($withoutAssessment['is_complete'])->toBeFalse()
        ->and($withoutAssessment['required'])->toBe(37);

    esrsAssessment($period['id'], ['E1']);

    $report = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs/completeness")->assertOk()->json('data');
    $byStandard = collect($report['standards'])->keyBy('standard');
    expect($report['issues'])->toBe(['datapoints_missing'])
        ->and($byStandard['E1']['required'])->toBe(9)
        ->and($byStandard['ESRS2']['required'])->toBe(5)
        ->and($byStandard['S1']['required'])->toBe(0)
        ->and($byStandard['S1']['is_material'])->toBeFalse()
        ->and($report['required'])->toBe(14)
        ->and($report['warnings'])->toContain('scope3_categories_without_data', 'period_open');

    $package = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs")->assertOk()->json('data');
    expect(collect($package['datapoints'])->firstWhere('code', 'S1-1')['is_required'])->toBeFalse()
        ->and(collect($package['datapoints'])->firstWhere('code', 'E1-1')['is_required'])->toBeTrue();
});

it('reaches a complete package when every required data point is approved or omitted with a reason', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk();
    esrsAssessment($period['id'], ['E1']);

    $package = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs")->assertOk()->json('data.datapoints');
    $lines = [];
    foreach (array_filter($package, static fn (array $row): bool => $row['is_required']) as $row) {
        if ($row['code'] === 'E1-5-ENERGY') {
            $lines[] = ['datapoint_id' => $row['id'], 'status' => 'not_applicable'];

            continue;
        }
        $lines[] = ['datapoint_id' => $row['id'], 'status' => 'approved', ...($row['data_type'] === 'number' ? ['value_number' => '12.5'] : ['value_text' => 'Described'])];
    }

    $this->putJson("/api/v1/sustainability/periods/{$period['id']}/esrs/values", ['values' => $lines])->assertUnprocessable()->assertJsonValidationErrors('values.'.array_search('E1-5-ENERGY', array_column(array_filter($package, static fn (array $row): bool => $row['is_required']), 'code'), true).'.omission_reason');

    $lines = array_map(static fn (array $line): array => $line['status'] === 'not_applicable' ? [...$line, 'omission_reason' => 'No energy-intensive activity'] : $line, $lines);
    $this->putJson("/api/v1/sustainability/periods/{$period['id']}/esrs/values", ['values' => $lines])->assertOk();

    $report = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs/completeness")->assertOk()->json('data');
    expect($report['is_complete'])->toBeTrue()
        ->and($report['percent'])->toBe('100.00')
        ->and($report['missing'])->toBe([]);
});

it('reserves the approved status to approvers and rejects a value without content', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk();
    $datapoint = collect($this->getJson('/api/v1/sustainability/esrs/datapoints?standard=E3')->json('data'))->firstWhere('code', 'E3-4-WATER');

    $this->putJson("/api/v1/sustainability/periods/{$period['id']}/esrs/values", ['values' => [['datapoint_id' => $datapoint['id'], 'status' => 'reviewed']]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.0.value_number');

    esrsAct(esrsMember($this->tenant, 'production'), $this->tenant);
    $this->putJson("/api/v1/sustainability/periods/{$period['id']}/esrs/values", ['values' => [['datapoint_id' => $datapoint['id'], 'status' => 'approved', 'value_number' => '5']]])
        ->assertUnprocessable()->assertJsonValidationErrors('values.0.status');
    $this->putJson("/api/v1/sustainability/periods/{$period['id']}/esrs/values", ['values' => [['datapoint_id' => $datapoint['id'], 'status' => 'reviewed', 'value_number' => '5']]])
        ->assertOk()->assertJsonPath('data.0.status', 'reviewed');
});

it('fills the emission data points from the inventory and keeps approved values', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk();
    $this->postJson('/api/v1/sustainability/factors/install-examples')->assertOk();
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'electricity_grid', 'region' => 'IT', 'activity_date' => '2027-03-01', 'quantity' => '10000', 'unit' => 'kWh'])->assertCreated();
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'fuel_diesel', 'activity_date' => '2027-03-02', 'quantity' => '1000', 'unit' => 'l'])->assertCreated();

    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/esrs/sync-inventory")->assertOk()->assertJsonPath('updated', 6)->assertJsonPath('skipped_approved', 0);

    $values = collect($this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs")->json('data.datapoints'))->keyBy('code');
    expect($values['E1-6-SCOPE1']['value']['value_number'])->toBe('2.6700')
        ->and($values['E1-6-SCOPE2']['value']['value_number'])->toBe('2.5000')
        ->and($values['E1-6-TOTAL']['value']['value_number'])->toBe('5.1700')
        ->and($values['E1-6-INTENSITY']['value']['value_number'])->toBe('2.5850')
        ->and($values['S1-6-HEADCOUNT']['value']['value_number'])->toBe('20.0000')
        ->and($values['E1-6-SCOPE1']['value']['source'])->toBe('inventory');

    $this->putJson("/api/v1/sustainability/periods/{$period['id']}/esrs/values", ['values' => [['datapoint_id' => $values['E1-6-SCOPE1']['id'], 'status' => 'approved', 'value_number' => '2.67']]])->assertOk();
    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/esrs/sync-inventory")->assertOk()->assertJsonPath('updated', 5)->assertJsonPath('skipped_approved', 1);
});

it('computes Scope 3 by the fifteen categories', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/factors/install-examples')->assertOk();
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'purchased_goods', 'activity_date' => '2027-02-01', 'quantity' => '10000', 'unit' => 'EUR'])->assertCreated();
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'freight_road', 'activity_date' => '2027-02-02', 'quantity' => '1000', 'unit' => 'tkm'])->assertCreated();
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'investments', 'activity_date' => '2027-02-03', 'quantity' => '5000', 'unit' => 'EUR', 'data_quality' => 'estimated'])->assertCreated();
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'water', 'activity_date' => '2027-02-04', 'quantity' => '100', 'unit' => 'm3'])->assertCreated();

    $report = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/scope3")->assertOk()->json('data');
    $categories = collect($report['categories'])->keyBy('category');

    expect($report['categories'])->toHaveCount(15)
        ->and($categories[1]['co2e_kg'])->toBe('4034.4000')
        ->and($categories[1]['records'])->toBe(2)
        ->and($categories[4]['co2e_kg'])->toBe('96.0000')
        ->and($categories[15]['co2e_kg'])->toBe('1000.0000')
        ->and($categories[15]['estimated_percent'])->toBe('100.00')
        ->and($categories[6]['has_data'])->toBeFalse()
        ->and($report['categories_with_data'])->toBe(3)
        ->and($report['total_kg'])->toBe('5130.4000')
        ->and($report['supplier_specific_percent'])->toBe('0.00');
});

it('collects supplier-specific data through the supplier portal requirement and feeds Scope 3', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/factors/install-examples')->assertOk();
    $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);

    $payload = ['reporting_period_id' => $period['id'], 'supplier_id' => $supplier->id, 'scope3_category' => 1, 'activity_type' => 'purchased_goods', 'unit' => 'EUR', 'quantity' => '20000', 'due_date' => '2027-09-30'];

    $this->postJson('/api/v1/sustainability/supplier-requests', [...$payload, 'scope3_category' => 6])->assertUnprocessable()->assertJsonValidationErrors('scope3_category');
    $this->postJson('/api/v1/sustainability/supplier-requests', [...$payload, 'supplier_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    $this->postJson('/api/v1/sustainability/supplier-requests', [...$payload, 'activity_type' => 'natural_gas', 'unit' => 'm3'])->assertUnprocessable()->assertJsonValidationErrors('activity_type');

    $request = $this->postJson('/api/v1/sustainability/supplier-requests', $payload)->assertCreated()->assertJsonPath('data.status', 'requested')->json('data');
    $requirement = DocumentRequirement::query()->where('tenant_id', $this->tenant->id)->where('code', EsgSupplierDataRequest::RequirementCode)->first();
    expect($requirement)->not->toBeNull()
        ->and($request['document_requirement_id'])->toBe($requirement->id)
        ->and($request['supplier_name'])->toBe($supplier->company_name);

    $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs/completeness")->assertOk()->assertJsonPath('data.open_supplier_requests', 1);
    $this->postJson("/api/v1/sustainability/supplier-requests/{$request['id']}/apply")->assertUnprocessable()->assertJsonValidationErrors('status');

    $foreign = SupplierDocumentSubmission::factory()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => (string) Str::uuid(), 'requirement_id' => $requirement->id]);
    $this->postJson("/api/v1/sustainability/supplier-requests/{$request['id']}/receive", ['reported_co2e_kg' => '3000', 'submission_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('submission_id');

    $submission = SupplierDocumentSubmission::factory()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'requirement_id' => $requirement->id]);
    $this->postJson("/api/v1/sustainability/supplier-requests/{$request['id']}/receive", ['reported_co2e_kg' => '3000', 'calculation_method' => 'Product carbon footprint', 'submission_id' => $submission->id])
        ->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.submission_id', $submission->id);

    $applied = $this->postJson("/api/v1/sustainability/supplier-requests/{$request['id']}/apply")->assertOk()->assertJsonPath('data.status', 'applied')->json('data');
    $record = $this->getJson("/api/v1/sustainability/activity-records/{$applied['activity_record_id']}")->assertOk()->json('data');
    expect($record['co2e_kg'])->toBe('3000.0000')
        ->and($record['factor_source'])->toContain('Supplier-specific data of');

    $scope3 = $this->getJson("/api/v1/sustainability/periods/{$period['id']}/scope3")->assertOk()->json('data');
    $first = collect($scope3['categories'])->firstWhere('category', 1);
    expect($first['supplier_specific_kg'])->toBe('3000.0000')
        ->and($first['supplier_specific_percent'])->toBe('100.00')
        ->and($first['open_supplier_requests'])->toBe(0)
        ->and($scope3['supplier_specific_percent'])->toBe('100.00');

    $this->postJson("/api/v1/sustainability/supplier-requests/{$request['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/api/v1/sustainability/supplier-requests?status=applied')->assertOk()->assertJsonCount(1, 'data');
});

it('declines and cancels open supplier requests', function (): void {
    $period = esrsPeriod();
    $supplier = Supplier::factory()->create(['tenant_id' => $this->tenant->id]);
    $payload = ['reporting_period_id' => $period['id'], 'supplier_id' => $supplier->id, 'scope3_category' => 2, 'activity_type' => 'capital_goods', 'unit' => 'EUR', 'quantity' => '1000'];

    $declined = $this->postJson('/api/v1/sustainability/supplier-requests', $payload)->assertCreated()->json('data.id');
    $cancelled = $this->postJson('/api/v1/sustainability/supplier-requests', $payload)->assertCreated()->json('data.id');

    $this->postJson("/api/v1/sustainability/supplier-requests/{$declined}/decline")->assertOk()->assertJsonPath('data.status', 'declined');
    $this->postJson("/api/v1/sustainability/supplier-requests/{$cancelled}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/sustainability/supplier-requests/{$declined}/cancel")->assertUnprocessable();
    $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs/completeness")->assertOk()->assertJsonPath('data.open_supplier_requests', 0);
});

it('keeps the ESRS records inside the active company', function (): void {
    $period = esrsPeriod();
    $this->postJson('/api/v1/sustainability/esrs/install-catalog')->assertOk();

    $other = Tenant::create(['name' => 'Other '.Str::random(4), 'legal_name' => 'Other Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    esrsAct(esrsMember($other, 'owner'), $other);

    $this->getJson('/api/v1/sustainability/esrs/datapoints')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/sustainability/periods/{$period['id']}/esrs")->assertNotFound();
    $this->getJson("/api/v1/sustainability/periods/{$period['id']}/scope3")->assertNotFound();
});
