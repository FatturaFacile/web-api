<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Quality\Models\QmsInstrument;
use Modules\Quality\Models\QmsNonconformity;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2027-06-15 09:00:00');
    $this->tenant = qxTenant('Metalmeccanica');
    $this->owner = qxMember($this->tenant, 'owner');
    qxAct($this->owner, $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

function qxTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
}

function qxMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@qx-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function qxAct(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeaders(['X-Tenant-Id' => (string) $tenant->id]);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function qxCharacteristic(array $overrides = []): array
{
    return test()->postJson('/api/v1/quality/spc/characteristics', [
        'code' => 'DIA-'.random_int(1000, 9999),
        'name' => 'Shaft diameter',
        'unit' => 'mm',
        'lower_spec_limit' => 9,
        'upper_spec_limit' => 11,
        'subgroup_size' => 2,
        ...$overrides,
    ])->assertCreated()->json('data');
}

/**
 * A subgroup of two measurements with the given mean and a range of 0.2.
 */
function qxSample(string $characteristicId, float $mean, array $extra = [])
{
    return test()->postJson("/api/v1/quality/spc/characteristics/$characteristicId/samples", [
        'measurements' => [$mean - 0.1, $mean + 0.1],
        ...$extra,
    ]);
}

it('computes the X-bar/R limits and the capability indices of a stable process', function (): void {
    $characteristic = qxCharacteristic();
    foreach (range(1, 4) as $cycle) {
        foreach ([10.1, 9.9, 10.0, 10.05, 9.95] as $mean) {
            qxSample($characteristic['id'], $mean)->assertCreated()->assertJsonPath('violations', []);
        }
    }

    $chart = $this->getJson("/api/v1/quality/spc/characteristics/{$characteristic['id']}/chart")->assertOk()->json('data');

    expect($chart['subgroup_count'])->toBe(20)
        ->and($chart['is_reliable'])->toBeTrue()
        ->and($chart['in_control'])->toBeTrue()
        ->and($chart['limits']['xbar']['center'])->toEqualWithDelta(10.0, 0.0001)
        ->and($chart['limits']['xbar']['ucl'])->toEqualWithDelta(10.376, 0.0001)
        ->and($chart['limits']['xbar']['lcl'])->toEqualWithDelta(9.624, 0.0001)
        ->and($chart['limits']['range']['ucl'])->toEqualWithDelta(0.6534, 0.0001)
        ->and($chart['limits']['range']['lcl'])->toEqualWithDelta(0.0, 0.0001)
        ->and($chart['capability']['cp'])->toEqualWithDelta(1.88, 0.001)
        ->and($chart['capability']['cpk'])->toEqualWithDelta(1.88, 0.001)
        ->and($chart['capability']['pp'])->toBeGreaterThan(0.0)
        ->and($chart['capability']['sigma_overall'])->toBeGreaterThan(0.0);
});

it('flags a point beyond the control limits and writes it to the audit ledger', function (): void {
    $characteristic = qxCharacteristic();
    foreach (range(1, 4) as $cycle) {
        foreach ([10.1, 9.9, 10.0, 10.05, 9.95] as $mean) {
            qxSample($characteristic['id'], $mean)->assertCreated();
        }
    }

    $response = qxSample($characteristic['id'], 12.1)->assertCreated();

    expect(collect($response->json('violations'))->pluck('rule')->all())->toContain(1)
        ->and($response->json('violations.0.sample_id'))->toBe($response->json('data.id'));
    $this->getJson("/api/v1/quality/spc/characteristics/{$characteristic['id']}/chart")->assertJsonPath('data.in_control', false);
    expect(AuditLedgerEntry::query()->where('action', 'QMS_SPC_OUT_OF_CONTROL')->exists())->toBeTrue();
});

it('detects nine points in a row on one side of the center line', function (): void {
    $characteristic = qxCharacteristic();
    foreach (range(1, 9) as $i) {
        qxSample($characteristic['id'], 10.1)->assertCreated();
    }
    foreach (range(1, 9) as $i) {
        qxSample($characteristic['id'], 9.9)->assertCreated();
    }

    $chart = $this->getJson("/api/v1/quality/spc/characteristics/{$characteristic['id']}/chart")->assertOk()->json('data');
    $rules = collect($chart['violations'])->where('chart', 'xbar');

    expect($rules->pluck('rule')->unique()->all())->toContain(2)
        ->and($rules->pluck('rule')->unique()->all())->not->toContain(4)
        ->and($chart['in_control'])->toBeFalse();
});

it('validates the subgroup size and the specification limits', function (): void {
    $characteristic = qxCharacteristic(['subgroup_size' => 3]);

    $this->postJson("/api/v1/quality/spc/characteristics/{$characteristic['id']}/samples", ['measurements' => [1, 2]])->assertStatus(422)->assertJsonValidationErrors('measurements');
    $this->postJson('/api/v1/quality/spc/characteristics', ['code' => 'X1', 'name' => 'x', 'subgroup_size' => 11])->assertStatus(422)->assertJsonValidationErrors('subgroup_size');
    $this->postJson('/api/v1/quality/spc/characteristics', ['code' => 'X2', 'name' => 'x', 'subgroup_size' => 3, 'lower_spec_limit' => 5, 'upper_spec_limit' => 4])->assertStatus(422)->assertJsonValidationErrors('upper_spec_limit');
    $this->postJson('/api/v1/quality/spc/characteristics', ['code' => $characteristic['code'], 'name' => 'dup', 'subgroup_size' => 3])->assertStatus(422)->assertJsonValidationErrors('code');

    $this->postJson("/api/v1/quality/spc/characteristics/{$characteristic['id']}/samples", ['measurements' => [1, 2, 3]])->assertCreated();
    $this->putJson("/api/v1/quality/spc/characteristics/{$characteristic['id']}", ['subgroup_size' => 4])->assertStatus(422);
});

it('plans calibrations, quarantines an instrument that is out of tolerance and lists the impacted samples', function (): void {
    $instrument = $this->postJson('/api/v1/quality/instruments', [
        'code' => 'CAL-001', 'name' => 'Micrometer', 'tolerance' => 0.05, 'calibration_interval_days' => 180,
    ])->assertCreated()->assertJsonPath('data.calibration_status', 'overdue')->json('data');
    $this->getJson('/api/v1/quality/instruments?calibration=overdue')->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/quality/instruments/{$instrument['id']}/calibrations", ['calibrated_on' => '2027-01-10', 'result' => 'pass', 'as_found_error' => 0.1])
        ->assertStatus(422)->assertJsonValidationErrors('result');

    $first = $this->postJson("/api/v1/quality/instruments/{$instrument['id']}/calibrations", [
        'calibrated_on' => '2027-01-10', 'result' => 'pass', 'as_found_error' => 0.01, 'certificate_number' => 'LAT-123',
    ])->assertCreated()->assertJsonPath('data.next_due_on', '2027-07-09')->json('data');
    $this->getJson("/api/v1/quality/instruments/{$instrument['id']}")->assertJsonPath('data.calibration_status', 'due_soon')->assertJsonPath('data.status', 'active');

    $characteristic = qxCharacteristic(['instrument_id' => $instrument['id']]);
    qxSample($characteristic['id'], 10.0, ['sampled_at' => '2027-03-01 10:00:00', 'lot' => 'L-77'])->assertCreated();
    qxSample($characteristic['id'], 10.0, ['sampled_at' => '2027-06-20 10:00:00'])->assertCreated();

    $second = $this->postJson("/api/v1/quality/instruments/{$instrument['id']}/calibrations", [
        'calibrated_on' => '2027-06-15', 'result' => 'out_of_tolerance', 'as_found_error' => 0.2,
    ])->assertCreated()->json('data');

    $this->getJson("/api/v1/quality/instruments/{$instrument['id']}")->assertJsonPath('data.status', 'quarantined')->assertJsonPath('data.calibration_status', 'quarantined');
    $nonconformity = QmsNonconformity::query()->findOrFail($second['nonconformity_id']);
    expect($nonconformity->source)->toBe('internal')->and($nonconformity->severity)->toBe('major');

    $impact = $this->getJson("/api/v1/quality/calibrations/{$second['id']}/impact")->assertOk()->json('data');
    expect($impact['from'])->toBe('2027-01-10')
        ->and($impact['sample_count'])->toBe(1)
        ->and($impact['characteristics'][0]['lots'])->toBe(['L-77']);
    $this->getJson("/api/v1/quality/calibrations/{$first['id']}/impact")->assertJsonPath('data.sample_count', 0);

    $this->postJson("/api/v1/quality/instruments/{$instrument['id']}/calibrations", ['calibrated_on' => '2027-06-16', 'result' => 'adjusted'])->assertCreated();
    $this->getJson("/api/v1/quality/instruments/{$instrument['id']}")->assertJsonPath('data.status', 'active');

    $this->postJson("/api/v1/quality/instruments/{$instrument['id']}/retire")->assertOk()->assertJsonPath('data.status', 'retired');
    $this->postJson("/api/v1/quality/instruments/{$instrument['id']}/calibrations", ['calibrated_on' => '2027-06-17', 'result' => 'pass'])->assertStatus(422);
});

it('keeps instruments inside the company', function (): void {
    $other = qxTenant('Altra');
    app(TenantContext::class)->setTenant($other);
    $foreign = QmsInstrument::factory()->create();
    qxAct($this->owner, $this->tenant);

    $this->getJson("/api/v1/quality/instruments/{$foreign->id}")->assertNotFound();
    $this->postJson('/api/v1/quality/instruments', ['code' => 'A', 'name' => 'a', 'calibration_interval_days' => 30])->assertCreated();
    $this->postJson('/api/v1/quality/spc/characteristics', ['code' => 'B', 'name' => 'b', 'subgroup_size' => 3, 'instrument_id' => $foreign->id])->assertStatus(422)->assertJsonValidationErrors('instrument_id');
});

it('runs a complaint from intake to the customer reply and closure', function (): void {
    $complaint = $this->postJson('/api/v1/quality/complaints', [
        'customer_name' => 'Acme Spa', 'description' => 'Burrs on the delivered shafts', 'severity' => 'major', 'channel' => 'phone', 'reference' => 'ORD-9',
    ])->assertCreated()->assertJsonPath('data.status', 'received')->assertJsonPath('data.number', 'CMP-2027-0001')->json('data');
    $url = "/api/v1/quality/complaints/{$complaint['id']}";

    $this->postJson("$url/reply", ['reply_text' => 'Too early'])->assertStatus(422);
    $this->postJson("$url/investigate", ['owner_id' => $this->owner->id])->assertOk()->assertJsonPath('data.status', 'investigating');
    $this->postJson("$url/reply", ['reply_text' => 'We are replacing the lot.'])->assertOk()->assertJsonPath('data.status', 'replied');
    $this->postJson("$url/close")->assertStatus(422)->assertJsonValidationErrors('root_cause');

    $this->postJson("$url/root-cause", ['root_cause' => 'Worn deburring tool'])->assertOk();
    $linked = $this->postJson("$url/raise-nonconformity")->assertOk()->json('data');
    $this->postJson("$url/raise-nonconformity")->assertStatus(422);
    $nonconformity = QmsNonconformity::query()->findOrFail($linked['nonconformity_id']);
    expect($nonconformity->source)->toBe('customer_complaint')->and($nonconformity->severity)->toBe('major');

    $this->postJson("$url/close")->assertStatus(422)->assertJsonValidationErrors('nonconformity_id');

    $this->postJson("/api/v1/quality/nonconformities/{$nonconformity->id}/investigate")->assertOk();
    $this->putJson("/api/v1/quality/nonconformities/{$nonconformity->id}", ['root_cause' => 'Worn deburring tool'])->assertOk();
    $capa = $this->postJson('/api/v1/quality/capa-actions', [
        'nonconformity_id' => $nonconformity->id, 'title' => 'Replace tool', 'owner_id' => $this->owner->id, 'due_date' => '2027-06-30',
    ])->assertCreated()->json('data');
    $this->postJson("$url/link", ['capa_action_id' => $capa['id']])->assertOk()->assertJsonPath('data.capa_action_id', $capa['id']);
    $this->postJson("/api/v1/quality/capa-actions/{$capa['id']}/cancel")->assertOk();
    $this->postJson("/api/v1/quality/nonconformities/{$nonconformity->id}/cancel", ['reason' => 'Handled in the complaint'])->assertOk();

    $this->postJson("$url/close", ['closure_notes' => 'Customer satisfied'])->assertOk()->assertJsonPath('data.status', 'closed');
    $this->putJson($url, ['description' => 'late edit'])->assertStatus(422);
    $this->getJson('/api/v1/quality/complaints?open=1')->assertJsonCount(0, 'data');
});

it('rejects a complaint that is not justified and refuses a CAPA of another non-conformity', function (): void {
    $complaint = $this->postJson('/api/v1/quality/complaints', ['customer_name' => 'Beta', 'description' => 'Wrong colour'])->assertCreated()->json('data');
    $url = "/api/v1/quality/complaints/{$complaint['id']}";

    $first = $this->postJson('/api/v1/quality/nonconformities', ['title' => 'A', 'source' => 'internal'])->assertCreated()->json('data');
    $second = $this->postJson('/api/v1/quality/nonconformities', ['title' => 'B', 'source' => 'internal'])->assertCreated()->json('data');
    $capa = $this->postJson('/api/v1/quality/capa-actions', ['nonconformity_id' => $second['id'], 'title' => 'C', 'owner_id' => $this->owner->id, 'due_date' => '2027-06-30'])->assertCreated()->json('data');

    $this->postJson("$url/link", ['nonconformity_id' => $first['id'], 'capa_action_id' => $capa['id']])->assertStatus(422)->assertJsonValidationErrors('capa_action_id');
    $this->postJson("$url/link", [])->assertStatus(422);

    $this->postJson("$url/reject", ['reason' => 'Product matches the order'])->assertOk()->assertJsonPath('data.status', 'rejected');
    $this->postJson("$url/investigate")->assertStatus(422);
});

it('lets read-only members read but not change quality records', function (): void {
    $reader = qxMember($this->tenant, 'sales');
    $this->postJson('/api/v1/quality/complaints', ['customer_name' => 'Gamma', 'description' => 'x'])->assertCreated();
    qxAct($reader, $this->tenant);

    $this->getJson('/api/v1/quality/complaints')->assertOk();
    $this->getJson('/api/v1/quality/instruments')->assertOk();
    $this->postJson('/api/v1/quality/complaints', ['customer_name' => 'Delta', 'description' => 'x'])->assertForbidden();
    $this->postJson('/api/v1/quality/instruments', ['code' => 'Z', 'name' => 'z', 'calibration_interval_days' => 30])->assertForbidden();
});
