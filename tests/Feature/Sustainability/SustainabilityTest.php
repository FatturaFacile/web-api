<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgEmissionFactor;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = esgTenant('Verde');
    $this->owner = esgMember($this->tenant, 'owner');
    esgAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function esgTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function esgMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@esg-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function esgAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function esgFactor(array $overrides = []): array
{
    return test()->postJson('/api/v1/sustainability/factors', [
        'code' => 'ELEC-IT-'.Str::upper(Str::random(4)),
        'name' => 'Grid electricity Italy',
        'activity_type' => 'electricity_grid',
        'unit' => 'kWh',
        'kg_co2e_per_unit' => '0.25',
        'region' => 'IT',
        'source' => 'Test source',
        'source_year' => 2024,
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function esgActivity(string $factorId, array $overrides = []): array
{
    return test()->postJson('/api/v1/sustainability/activity-records', [
        'emission_factor_id' => $factorId,
        'activity_date' => '2027-03-10',
        'quantity' => '1000',
        'unit' => 'kWh',
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function esgPeriod(array $overrides = []): array
{
    return test()->postJson('/api/v1/sustainability/periods', [
        'name' => 'FY 2027',
        'starts_on' => '2027-01-01',
        'ends_on' => '2027-12-31',
        ...$overrides,
    ])->assertCreated()->json('data');
}

it('computes CO2e with exact decimals and freezes the factor on the record', function (): void {
    $factor = esgFactor(['kg_co2e_per_unit' => '0.2500']);

    $record = esgActivity($factor['id'], ['quantity' => '1234.5']);

    expect($record['co2e_kg'])->toBe('308.6250')
        ->and($record['scope'])->toBe(2)
        ->and($record['category'])->toBe('purchased_electricity')
        ->and($record['factor_version'])->toBe(1)
        ->and($record['factor_kg_co2e_per_unit'])->toBe('0.25000000');

    $next = $this->postJson("/api/v1/sustainability/factors/{$factor['id']}/new-version", ['kg_co2e_per_unit' => '0.20', 'source' => 'Updated source', 'source_year' => 2025])
        ->assertCreated()->json('data');

    expect($next['version'])->toBe(2)->and($next['code'])->toBe($factor['code']);

    $this->getJson("/api/v1/sustainability/activity-records/{$record['id']}")->assertOk()
        ->assertJsonPath('data.co2e_kg', '308.6250')
        ->assertJsonPath('data.factor_version', 1);

    $resolved = $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'electricity_grid', 'region' => 'IT', 'activity_date' => '2027-03-11', 'quantity' => '1000', 'unit' => 'kWh'])
        ->assertCreated()->json('data');

    expect($resolved['factor_version'])->toBe(2)->and($resolved['co2e_kg'])->toBe('200.0000');
});

it('keeps a factor in use immutable, versions it and only deactivates it when deleted', function (): void {
    $factor = esgFactor();
    esgActivity($factor['id']);

    $this->putJson("/api/v1/sustainability/factors/{$factor['id']}", ['kg_co2e_per_unit' => '0.30'])->assertUnprocessable()->assertJsonValidationErrors(['kg_co2e_per_unit']);
    $this->putJson("/api/v1/sustainability/factors/{$factor['id']}", ['name' => 'Renamed', 'notes' => 'Checked'])->assertOk()->assertJsonPath('data.name', 'Renamed');

    $this->deleteJson("/api/v1/sustainability/factors/{$factor['id']}")->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson('/api/v1/sustainability/activity-records', ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-03-12', 'quantity' => '5', 'unit' => 'kWh'])
        ->assertUnprocessable()->assertJsonValidationErrors(['emission_factor_id']);

    $unused = esgFactor();
    $this->deleteJson("/api/v1/sustainability/factors/{$unused['id']}")->assertNoContent();
    expect(EsgEmissionFactor::query()->whereKey($unused['id'])->withTrashed()->exists())->toBeFalse();
});

it('validates units, activity types and the factor of an activity record', function (): void {
    $this->postJson('/api/v1/sustainability/factors', ['code' => 'BAD', 'name' => 'Bad', 'activity_type' => 'natural_gas', 'unit' => 'kWh', 'kg_co2e_per_unit' => '2', 'source' => 'x', 'source_year' => 2024])
        ->assertUnprocessable()->assertJsonValidationErrors(['unit']);

    $gas = esgFactor(['code' => 'GAS', 'activity_type' => 'natural_gas', 'unit' => 'm3', 'kg_co2e_per_unit' => '2.0', 'region' => 'EU']);

    $this->postJson('/api/v1/sustainability/activity-records', ['emission_factor_id' => $gas['id'], 'activity_date' => '2027-03-10', 'quantity' => '10', 'unit' => 'kWh'])
        ->assertUnprocessable()->assertJsonValidationErrors(['unit']);
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'waste', 'activity_date' => '2027-03-10', 'quantity' => '10', 'unit' => 'kg'])
        ->assertUnprocessable()->assertJsonValidationErrors(['activity_type']);
    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'natural_gas', 'region' => 'CH', 'activity_date' => '2027-03-10', 'quantity' => '10', 'unit' => 'm3'])
        ->assertCreated()->assertJsonPath('data.factor_code', 'GAS')->assertJsonPath('data.scope', 1);
    $this->postJson('/api/v1/sustainability/activity-records', ['emission_factor_id' => $gas['id'], 'activity_date' => '2027-03-10', 'quantity' => '0', 'unit' => 'm3'])
        ->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
});

it('installs the example factors and indicators once, marked as examples', function (): void {
    $this->postJson('/api/v1/sustainability/factors/install-examples')->assertOk()->assertJsonPath('created', 28);
    $this->postJson('/api/v1/sustainability/factors/install-examples')->assertOk()->assertJsonPath('created', 0);
    $this->postJson('/api/v1/sustainability/kpis/install-defaults')->assertOk()->assertJsonPath('created', 14);
    $this->postJson('/api/v1/sustainability/kpis/install-defaults')->assertOk()->assertJsonPath('created', 0);

    $factors = $this->getJson('/api/v1/sustainability/factors?region=CH&per_page=100')->assertOk()->json('data');
    expect(collect($factors)->every(fn (array $factor): bool => $factor['is_example'] === true))->toBeTrue()
        ->and(collect($factors)->pluck('code')->all())->toContain('EX-ELEC-CH');

    $this->getJson('/api/v1/sustainability/kpis?pillar=social')->assertOk()->assertJsonCount(6, 'data');
    $this->getJson('/api/v1/sustainability/catalog')->assertOk()->assertJsonPath('data.activity_types.0.activity_type', 'electricity_grid');

    $this->postJson('/api/v1/sustainability/activity-records', ['activity_type' => 'purchased_goods', 'region' => 'CH', 'activity_date' => '2027-02-01', 'quantity' => '10000', 'unit' => 'CHF'])
        ->assertCreated()->assertJsonPath('data.co2e_kg', '4200.0000');
});

it('locks the data of a closed period and reopening is reserved to approvers', function (): void {
    $factor = esgFactor();
    $record = esgActivity($factor['id']);
    $period = esgPeriod();

    $this->postJson('/api/v1/sustainability/periods', ['name' => 'Overlap', 'starts_on' => '2027-12-01', 'ends_on' => '2028-02-01'])->assertUnprocessable()->assertJsonValidationErrors(['starts_on']);

    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/close")->assertUnprocessable();

    $this->postJson('/api/v1/sustainability/activity-records', ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-04-01', 'quantity' => '5', 'unit' => 'kWh'])->assertUnprocessable()->assertJsonValidationErrors(['activity_date']);
    $this->putJson("/api/v1/sustainability/activity-records/{$record['id']}", ['quantity' => '7'])->assertUnprocessable();
    $this->deleteJson("/api/v1/sustainability/activity-records/{$record['id']}")->assertUnprocessable();
    $this->putJson("/api/v1/sustainability/periods/{$period['id']}", ['name' => 'Renamed'])->assertUnprocessable();

    $member = esgMember($this->tenant, 'member');
    esgAct($member, $this->tenant);
    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/reopen")->assertForbidden();

    esgAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/reopen")->assertOk()->assertJsonPath('data.status', 'open');
    $this->putJson("/api/v1/sustainability/activity-records/{$record['id']}", ['quantity' => '7'])->assertOk()->assertJsonPath('data.co2e_kg', '1.7500');

    $actions = AuditLedgerEntry::query()->where('tenant_id', $this->tenant->id)->where('module_code', 'MODULE_47_SUSTAINABILITY')->pluck('action')->all();
    expect($actions)->toContain('ESG_PERIOD_CLOSED', 'ESG_PERIOD_REOPENED');
});

it('builds the inventory by scope and category with intensity and the change against the previous period', function (): void {
    $electricity = esgFactor(['kg_co2e_per_unit' => '0.25']);
    $gas = esgFactor(['code' => 'GAS-EU', 'activity_type' => 'natural_gas', 'unit' => 'm3', 'kg_co2e_per_unit' => '2', 'region' => 'EU']);
    $air = esgFactor(['code' => 'AIR-EU', 'activity_type' => 'business_travel_air', 'unit' => 'km', 'kg_co2e_per_unit' => '0.15', 'region' => 'EU']);

    esgPeriod(['name' => 'FY 2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    esgActivity($electricity['id'], ['activity_date' => '2026-05-01', 'quantity' => '4000']);
    esgActivity($gas['id'], ['activity_date' => '2026-05-01', 'quantity' => '500', 'unit' => 'm3']);

    $current = esgPeriod(['revenue' => '2000000', 'employees_fte' => '20']);
    esgActivity($electricity['id'], ['activity_date' => '2027-02-01', 'quantity' => '2000']);
    esgActivity($gas['id'], ['activity_date' => '2027-02-01', 'quantity' => '250', 'unit' => 'm3', 'data_quality' => 'estimated']);
    esgActivity($air['id'], ['activity_date' => '2027-02-01', 'quantity' => '10000', 'unit' => 'km']);

    $inventory = $this->getJson("/api/v1/sustainability/inventory?reporting_period_id={$current['id']}")->assertOk()->json('data');

    expect($inventory['totals'])->toBe(['scope_1_kg' => '500.0000', 'scope_2_kg' => '500.0000', 'scope_3_kg' => '1500.0000', 'total_kg' => '2500.0000', 'total_tco2e' => '2.500'])
        ->and($inventory['scopes'][2]['categories'][0]['category'])->toBe('business_travel')
        ->and($inventory['scopes'][2]['share_percent'])->toBe('60.00')
        ->and($inventory['data_quality']['estimated_share_percent'])->toBe('20.00')
        ->and($inventory['intensity']['tco2e_per_million_revenue'])->toBe('1.250')
        ->and($inventory['intensity']['tco2e_per_fte'])->toBe('0.125')
        ->and($inventory['previous']['period_name'])->toBe('FY 2026')
        ->and($inventory['previous']['totals']['total_kg'])->toBe('2000.0000')
        ->and($inventory['previous']['delta']['total'])->toBe(['absolute_kg' => '500.0000', 'percent' => '25.00'])
        ->and($inventory['previous']['delta']['scope_3'])->toBe(['absolute_kg' => '1500.0000', 'percent' => null]);

    $this->getJson('/api/v1/sustainability/inventory')->assertOk()->assertJsonPath('data.period.name', 'FY 2027');
});

it('freezes the baseline of a target and decides it against the latest year', function (): void {
    $electricity = esgFactor(['kg_co2e_per_unit' => '0.25']);

    $target = $this->postJson('/api/v1/sustainability/targets', ['name' => 'Cut electricity', 'scope' => 'scope_2', 'baseline_year' => 2025, 'target_year' => 2030, 'reduction_percent' => '40'])->assertCreated()->json('data');
    expect($target['progress'])->toBeNull();

    $this->postJson("/api/v1/sustainability/targets/{$target['id']}/activate")->assertUnprocessable()->assertJsonValidationErrors(['baseline_year']);
    $this->postJson('/api/v1/sustainability/targets', ['name' => 'Backwards', 'scope' => 'total', 'baseline_year' => 2030, 'target_year' => 2025, 'reduction_percent' => '10'])->assertUnprocessable()->assertJsonValidationErrors(['target_year']);

    esgActivity($electricity['id'], ['activity_date' => '2025-06-01', 'quantity' => '10000']);
    $active = $this->postJson("/api/v1/sustainability/targets/{$target['id']}/activate")->assertOk()->json('data');
    expect($active['status'])->toBe('active')->and($active['baseline_co2e_kg'])->toBe('2500.0000')->and($active['progress']['target_co2e_kg'])->toBe('1500.0000');

    $this->putJson("/api/v1/sustainability/targets/{$target['id']}", ['reduction_percent' => '50'])->assertUnprocessable();
    $this->putJson("/api/v1/sustainability/targets/{$target['id']}", ['name' => 'Cut electricity 2030'])->assertOk();
    $this->postJson("/api/v1/sustainability/targets/{$target['id']}/evaluate")->assertUnprocessable();

    $latest = esgActivity($electricity['id'], ['activity_date' => '2027-02-01', 'quantity' => '7000']);
    $progress = $this->getJson("/api/v1/sustainability/targets/{$target['id']}")->assertOk()->json('data.progress');
    expect($progress['current_year'])->toBe(2027)
        ->and($progress['reduction_achieved_percent'])->toBe('30.00')
        ->and($progress['expected_reduction_percent'])->toBe('16.00')
        ->and($progress['progress_percent'])->toBe('75.00')
        ->and($progress['on_track'])->toBeTrue();
    $this->postJson("/api/v1/sustainability/targets/{$target['id']}/evaluate")->assertUnprocessable();

    $this->putJson("/api/v1/sustainability/activity-records/{$latest['id']}", ['quantity' => '5000'])->assertOk();
    $this->postJson("/api/v1/sustainability/targets/{$target['id']}/evaluate")->assertOk()->assertJsonPath('data.status', 'achieved');
    $this->postJson("/api/v1/sustainability/targets/{$target['id']}/cancel")->assertUnprocessable();

    $past = $this->postJson('/api/v1/sustainability/targets', ['name' => 'Missed', 'scope' => 'total', 'baseline_year' => 2025, 'target_year' => 2026, 'reduction_percent' => '90'])->assertCreated()->json('data');
    $this->postJson("/api/v1/sustainability/targets/{$past['id']}/activate")->assertOk();
    $this->postJson("/api/v1/sustainability/targets/{$past['id']}/evaluate")->assertOk()->assertJsonPath('data.status', 'missed');
});

it('records ESG KPI values once per period, replaces them and locks closed periods', function (): void {
    $period = esgPeriod();
    $definition = $this->postJson('/api/v1/sustainability/kpis', ['code' => 'female_share', 'name' => 'Female share', 'pillar' => 'social', 'unit' => '%', 'direction' => 'higher_is_better', 'target_value' => '40'])->assertCreated()->json('data');

    $this->postJson('/api/v1/sustainability/kpis', ['code' => 'female_share', 'name' => 'Duplicate', 'pillar' => 'social', 'unit' => '%'])->assertUnprocessable()->assertJsonValidationErrors(['code']);

    $payload = ['kpi_definition_id' => $definition['id'], 'reporting_period_id' => $period['id'], 'value' => '35.5'];
    $this->putJson('/api/v1/sustainability/kpi-values', $payload)->assertCreated()->assertJsonPath('data.value', '35.5000')->assertJsonPath('data.target_met', false);
    $this->putJson('/api/v1/sustainability/kpi-values', [...$payload, 'value' => '41', 'data_quality' => 'estimated'])->assertOk()->assertJsonPath('data.target_met', true);

    $this->getJson("/api/v1/sustainability/kpi-values?reporting_period_id={$period['id']}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.data_quality', 'estimated');

    $this->putJson("/api/v1/sustainability/kpis/{$definition['id']}", ['is_active' => false])->assertOk();
    $this->putJson('/api/v1/sustainability/kpi-values', $payload)->assertUnprocessable()->assertJsonValidationErrors(['kpi_definition_id']);
    $this->putJson("/api/v1/sustainability/kpis/{$definition['id']}", ['is_active' => true])->assertOk();

    $this->postJson("/api/v1/sustainability/periods/{$period['id']}/close")->assertOk();
    $this->putJson('/api/v1/sustainability/kpi-values', $payload)->assertUnprocessable()->assertJsonValidationErrors(['reporting_period_id']);
});

it('scores a double materiality assessment, approves it once and then keeps it final', function (): void {
    $assessment = $this->postJson('/api/v1/sustainability/materiality-assessments', [
        'name' => 'DMA 2027',
        'threshold' => '3',
        'topics' => [
            ['esrs_code' => 'E1', 'name' => 'Climate change mitigation', 'impact_score' => '4.5', 'financial_score' => '3.5'],
            ['esrs_code' => 'E4', 'name' => 'Biodiversity', 'impact_score' => '3', 'financial_score' => '1.5'],
            ['esrs_code' => 'S1', 'name' => 'Working conditions', 'impact_score' => '2', 'financial_score' => '3.2'],
            ['esrs_code' => 'G1', 'name' => 'Business conduct', 'impact_score' => '2.5', 'financial_score' => '2.5'],
        ],
    ])->assertCreated()->json('data');

    $types = collect($assessment['topics'])->pluck('materiality_type', 'esrs_code')->all();
    expect($types)->toBe(['E1' => 'both', 'E4' => 'impact', 'G1' => 'none', 'S1' => 'financial'])
        ->and($assessment['material_topics_count'])->toBe(3);

    $this->postJson('/api/v1/sustainability/materiality-assessments', ['name' => 'Dup', 'topics' => [['esrs_code' => 'E1', 'name' => 'Same', 'impact_score' => '1', 'financial_score' => '1'], ['esrs_code' => 'E2', 'name' => 'Same', 'impact_score' => '1', 'financial_score' => '1']]])->assertUnprocessable();
    $this->postJson('/api/v1/sustainability/materiality-assessments', ['name' => 'Range', 'topics' => [['esrs_code' => 'E1', 'name' => 'Out', 'impact_score' => '6', 'financial_score' => '1']]])->assertUnprocessable();

    $raised = $this->putJson("/api/v1/sustainability/materiality-assessments/{$assessment['id']}", ['threshold' => '4'])->assertOk()->json('data');
    expect($raised['material_topics_count'])->toBe(1);

    $empty = $this->postJson('/api/v1/sustainability/materiality-assessments', ['name' => 'Empty'])->assertCreated()->json('data');
    $this->postJson("/api/v1/sustainability/materiality-assessments/{$empty['id']}/approve")->assertUnprocessable()->assertJsonValidationErrors(['topics']);

    $member = esgMember($this->tenant, 'member');
    esgAct($member, $this->tenant);
    $this->postJson("/api/v1/sustainability/materiality-assessments/{$assessment['id']}/approve")->assertForbidden();

    esgAct($this->owner, $this->tenant);
    $this->postJson("/api/v1/sustainability/materiality-assessments/{$assessment['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/v1/sustainability/materiality-assessments/{$assessment['id']}/approve")->assertUnprocessable();
    $this->putJson("/api/v1/sustainability/materiality-assessments/{$assessment['id']}", ['name' => 'Changed'])->assertUnprocessable();
    $this->getJson('/api/v1/sustainability/materiality-assessments')->assertOk()->assertJsonCount(2, 'data');
});

it('records several lines in one transaction and refuses the whole batch on one error', function (): void {
    $factor = esgFactor();

    $this->postJson('/api/v1/sustainability/activity-records/bulk', ['lines' => [
        ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-01-10', 'quantity' => '100', 'unit' => 'kWh'],
        ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-02-10', 'quantity' => '200', 'unit' => 'kWh', 'data_quality' => 'estimated'],
    ]])->assertCreated()->assertJsonCount(2, 'data');

    esgPeriod(['starts_on' => '2027-06-01', 'ends_on' => '2027-06-30']);
    $this->postJson('/api/v1/sustainability/periods/'.$this->getJson('/api/v1/sustainability/periods')->json('data.0.id').'/close')->assertOk();

    $this->postJson('/api/v1/sustainability/activity-records/bulk', ['lines' => [
        ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-01-11', 'quantity' => '100', 'unit' => 'kWh'],
        ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-06-11', 'quantity' => '100', 'unit' => 'kWh'],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['lines.1.activity_date']);

    expect(EsgActivityRecord::query()->count())->toBe(2);
});

it('isolates companies and refuses writes to read-only roles', function (): void {
    $factor = esgFactor();

    $other = esgTenant('Altra');
    $stranger = esgMember($other, 'owner');
    esgAct($stranger, $other);

    $this->getJson("/api/v1/sustainability/factors/{$factor['id']}")->assertNotFound();
    $this->postJson('/api/v1/sustainability/activity-records', ['emission_factor_id' => $factor['id'], 'activity_date' => '2027-03-10', 'quantity' => '1', 'unit' => 'kWh'])->assertUnprocessable();
    $this->getJson('/api/v1/sustainability/factors')->assertOk()->assertJsonCount(0, 'data');

    $reader = esgMember($this->tenant, 'accountant_readonly');
    esgAct($reader, $this->tenant);
    $this->getJson('/api/v1/sustainability/factors')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/sustainability/periods', ['name' => 'X', 'starts_on' => '2030-01-01', 'ends_on' => '2030-12-31'])->assertForbidden();
});
