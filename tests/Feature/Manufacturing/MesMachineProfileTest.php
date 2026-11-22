<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Manufacturing\Models\MfgWorkCenter;

require_once __DIR__.'/ManufacturingSupport.php';
require_once __DIR__.'/MesTestSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
    config(['manufacturing_mes.keys' => ['k1' => ['secret' => 'secret-one', 'tenants' => [(string) $this->tenant->id]]]]);
    $this->center = MfgWorkCenter::factory()->create(['tenant_id' => $this->tenant->id, 'work_center_code' => 'WC-A']);
});

test('the MES profile of a machine is configured through the API and served to the MES Core with the line and the ideal cycle', function (): void {
    $profile = [
        'tag_roles' => ['run' => 'Running', 'count' => 'TotalCounter'],
        'debounce_ms' => 2000,
        'stale_after_s' => 45,
        'counter' => ['rollover_modulus' => 65536, 'max_delta_per_sample' => 400],
        'oee_policy' => ['version' => 2, 'setup' => 'PlannedExcluded', 'gap' => 'CountAsDowntime'],
    ];

    $id = (string) $this->postJson('/api/v1/manufacturing/machines', [
        'machine_code' => 'M-PROFILE', 'name' => 'Press', 'work_center_id' => $this->center->id, 'ideal_cycle_time_seconds_per_unit' => '0.8', 'mes_profile' => $profile,
    ])->assertCreated()->assertJsonPath('data.mes_profile.oee_policy.gap', 'CountAsDowntime')->json('data.id');

    $payload = collect(mesCall($this, 'GET', '/api/v1/manufacturing/mes-sync/machines')->assertOk()->json('data'))->firstWhere('id', $id)['payload'];

    expect($payload['line_code'])->toBe('WC-A')
        ->and((float) $payload['ideal_cycle_ms'])->toBe(800.0)
        ->and(array_intersect_key($payload, $profile))->toBe($profile);
});

test('a machine without a profile is served with its identity, the line and no profile keys', function (): void {
    $id = (string) $this->postJson('/api/v1/manufacturing/machines', ['machine_code' => 'M-PLAIN', 'name' => 'Plain', 'work_center_id' => $this->center->id])->assertCreated()->json('data.id');

    $payload = collect(mesCall($this, 'GET', '/api/v1/manufacturing/mes-sync/machines')->assertOk()->json('data'))->firstWhere('id', $id)['payload'];

    expect($payload)->toHaveKey('line_code')->not->toHaveKeys(['ideal_cycle_ms', 'tag_roles', 'oee_policy']);
});

test('a profile the MES Core cannot read is refused', function (array $profile, string $field): void {
    $this->postJson('/api/v1/manufacturing/machines', ['machine_code' => 'M-BAD', 'name' => 'Bad', 'work_center_id' => $this->center->id, 'mes_profile' => $profile])
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'unknown tag role' => [['tag_roles' => ['run' => 'Flying']], 'mes_profile.tag_roles.run'],
    'unknown oee setup policy' => [['oee_policy' => ['setup' => 'Whatever']], 'mes_profile.oee_policy.setup'],
    'negative debounce' => [['debounce_ms' => -1], 'mes_profile.debounce_ms'],
    'unknown key' => [['colour' => 'red'], 'mes_profile'],
]);
