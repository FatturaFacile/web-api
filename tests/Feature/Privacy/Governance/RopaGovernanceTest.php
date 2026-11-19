<?php

declare(strict_types=1);

use App\Models\Privacy\DataRetentionPolicyRule;
use App\Models\Privacy\ProcessingActivityRecordRopa;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = PrivacyFixtures::tenant('Ropa AG');
    $this->headers = PrivacyFixtures::actAs(PrivacyFixtures::member($this->tenant), $this->tenant);
});

afterEach(fn () => app(TenantContext::class)->clear());

test('seed defaults is idempotent and creates ROPA-001..005 with their retention rules', function (): void {
    $first = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa/seed-defaults')->assertOk()->json('data');
    expect($first['ropa_created'])->toBe(5)->and($first['rules_created'])->toBe(5);

    $second = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa/seed-defaults')->assertOk()->json('data');
    expect($second['ropa_created'])->toBe(0)->and($second['ropa_existing'])->toBe(5);

    $records = ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant->id)->orderBy('activity_code')->get();
    expect($records->pluck('activity_code')->all())->toBe(['ROPA-001', 'ROPA-002', 'ROPA-003', 'ROPA-004', 'ROPA-005'])
        ->and($records[0]->retention_period_months)->toBe(120)
        ->and($records[0]->legal_basis->value)->toBe('legal_obligation')
        ->and($records[0]->retention_legal_reference)->toContain('958f')
        ->and($records[3]->retention_period_months)->toBe(6)
        ->and($records[3]->cross_border_transfers)->toBe([]);

    $locked = DataRetentionPolicyRule::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant->id)->where('is_statutory_locked', true)->pluck('module_code')->sort()->values()->all();
    expect($locked)->toBe(['MOD_026_INVOICES', 'MOD_076_PAYROLL']);
});

test('ropa crud validates, scopes the code per tenant and soft deletes', function (): void {
    $payload = [
        'activity_code' => 'ROPA-900', 'activity_name' => 'Custom', 'purpose' => 'Testing', 'legal_basis' => 'consent',
        'retention_period_months' => 12, 'cross_border_transfers' => [['country_code' => 'CH', 'adequacy_decision' => true, 'safeguard_mechanism' => 'Adequacy']],
    ];
    $id = $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa', $payload)->assertCreated()->json('data.id');
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa', $payload)->assertUnprocessable();
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa', ['legal_basis' => 'nonsense'] + $payload)->assertUnprocessable();

    $this->withHeaders($this->headers)->putJson("/api/v1/privacy/ropa/{$id}", ['activity_name' => 'Renamed'])->assertOk()->assertJsonPath('data.activity_name', 'Renamed');
    expect($this->withHeaders($this->headers)->getJson('/api/v1/privacy/ropa?cross_border=1')->assertOk()->json('data'))->toHaveCount(1)
        ->and($this->withHeaders($this->headers)->getJson('/api/v1/privacy/ropa?legal_basis=contract')->json('data'))->toHaveCount(0);

    $this->withHeaders($this->headers)->deleteJson("/api/v1/privacy/ropa/{$id}")->assertNoContent();
    expect($this->withHeaders($this->headers)->getJson('/api/v1/privacy/ropa')->json('data'))->toHaveCount(0);
});

test('ropa exports are valid pdf and xlsx binaries', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa/seed-defaults')->assertOk();

    $pdf = $this->withHeaders($this->headers)->get('/api/v1/privacy/ropa/export?format=pdf')->assertOk();
    expect($pdf->headers->get('content-type'))->toContain('application/pdf')->and(substr($pdf->getContent(), 0, 4))->toBe('%PDF');

    $xlsx = $this->withHeaders($this->headers)->get('/api/v1/privacy/ropa/export?format=xlsx')->assertOk();
    expect(substr($xlsx->getContent(), 0, 2))->toBe('PK')->and(strlen($xlsx->getContent()))->toBeGreaterThan(1000);
});

test('a tenant cannot see or change the ropa of another tenant', function (): void {
    $this->withHeaders($this->headers)->postJson('/api/v1/privacy/ropa/seed-defaults')->assertOk();
    $id = ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenant->id)->value('id');

    $other = PrivacyFixtures::tenant('Other AG');
    $headers = PrivacyFixtures::actAs(PrivacyFixtures::member($other), $other);

    expect($this->withHeaders($headers)->getJson('/api/v1/privacy/ropa')->assertOk()->json('data'))->toHaveCount(0);
    $this->withHeaders($headers)->putJson("/api/v1/privacy/ropa/{$id}", ['activity_name' => 'Hack'])->assertNotFound();
    $this->withHeaders($headers)->deleteJson("/api/v1/privacy/ropa/{$id}")->assertNotFound();
});
