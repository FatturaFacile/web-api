<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Exceptions\CrossBorderResidencyViolationException;
use Modules\DataResidency\Domain\Exceptions\DataResidencyException;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;
use Modules\DataResidency\Infrastructure\Services\SovereignDatabaseConnectionResolver;

require_once __DIR__.'/DataResidencyTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    drSeedCatalogue();
});

it('requires authentication and a manager role', function (): void {
    $this->getJson('/api/v1/sovereignty/nodes')->assertUnauthorized();

    drActAs(drTenant(), 'member');
    $this->getJson('/api/v1/sovereignty/nodes')->assertForbidden();
});

it('lists the sovereign nodes filtered by jurisdiction zone and service type', function (): void {
    drActAs(drTenant());

    $swiss = $this->getJson('/api/v1/sovereignty/nodes?jurisdiction_zone=Strict-CH-Only')->assertOk()->json('data');
    expect($swiss)->not->toBeEmpty()->and(collect($swiss)->pluck('country_iso2')->unique()->all())->toBe(['CH']);
    expect(collect($swiss)->pluck('city')->unique()->sort()->values()->all())->toBe(['Geneva', 'Lugano', 'Zurich']);

    $italian = $this->getJson('/api/v1/sovereignty/nodes?jurisdiction_zone=Strict-IT-Only&service_type=s3_dms')->assertOk()->json('data');
    expect(collect($italian)->pluck('node_code')->sort()->values()->all())->toBe(['it-mil-s3-01', 'it-rom-s3-01', 'it-tor-s3-01']);
    expect($italian[0]['certifications'])->toContain('ISO-27001');
});

it('enforces a Swiss-only policy on Swiss nodes and locks it', function (): void {
    $tenant = drTenant();
    drActAs($tenant);

    $this->postJson('/api/v1/sovereignty/policies', drSitePayload('ch-zrh', JurisdictionZone::StrictChOnly))
        ->assertOk()
        ->assertJsonPath('data.jurisdiction_zone', 'Strict-CH-Only')
        ->assertJsonPath('data.scope_type', 'TENANT_DEFAULT')
        ->assertJsonPath('data.enforce_strict_egress_block', true);

    $policy = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
    expect($policy->isLocked())->toBeTrue();
});

it('rejects an Italian node in a Strict-CH-Only policy for every service', function (string $column, string $italianNode): void {
    drActAs(drTenant());

    $payload = drSitePayload('ch-zrh', JurisdictionZone::StrictChOnly);
    $payload[$column] = drNode($italianNode)->id;

    $this->postJson('/api/v1/sovereignty/policies', $payload)
        ->assertStatus(422)
        ->assertJsonPath('invalid_params.0.reason', 'CROSS_BORDER_RESIDENCY_VIOLATION');
})->with([
    'database' => ['primary_db_node_id', 'it-mil-01'],
    'object storage' => ['s3_storage_node_id', 'it-mil-s3-01'],
    'pgvector' => ['vector_db_node_id', 'it-mil-vec-01'],
    'AI/OCR inference' => ['ai_inference_node_id', 'it-mil-ai-01'],
    'backup vault' => ['backup_node_id', 'it-mil-bkp-01'],
    'queue broker' => ['queue_node_id', 'it-mil-q-01'],
]);

it('rejects a Swiss replica in a Strict-IT-Only policy', function (): void {
    drActAs(drTenant());

    $payload = drSitePayload('it-mil', JurisdictionZone::StrictItOnly);
    $payload['replica_db_node_ids'] = [drNode('ch-zrh-02')->id];

    $this->postJson('/api/v1/sovereignty/policies', $payload)->assertStatus(422)->assertJsonPath('invalid_params.0.reason', 'CROSS_BORDER_RESIDENCY_VIOLATION');
});

it('accepts Swiss and Italian nodes inside the EEA corridor', function (): void {
    drActAs(drTenant());

    $payload = drSitePayload('it-mil', JurisdictionZone::EeaPlusAdequacyCh);
    $payload['backup_node_id'] = drNode('ch-lug-bkp-01')->id;

    $this->postJson('/api/v1/sovereignty/policies', $payload)->assertOk();
});

it('refuses a node of the wrong service type', function (): void {
    drActAs(drTenant());

    $payload = drSitePayload('ch-zrh', JurisdictionZone::StrictChOnly);
    $payload['primary_db_node_id'] = drNode('ch-zrh-s3-01')->id;

    $this->postJson('/api/v1/sovereignty/policies', $payload)->assertStatus(422);
});

it('moves a locked policy only with a second, different approver', function (): void {
    $tenant = drTenant();
    $actor = drActAs($tenant);
    $this->postJson('/api/v1/sovereignty/policies', drSitePayload('ch-zrh', JurisdictionZone::StrictChOnly))->assertOk();

    $migration = drSitePayload('ch-lug', JurisdictionZone::StrictChOnly);

    $this->postJson('/api/v1/sovereignty/policies', $migration)->assertStatus(422);
    $this->postJson('/api/v1/sovereignty/policies', $migration + ['second_approver_id' => $actor->id])->assertStatus(422);
    $this->postJson('/api/v1/sovereignty/policies', $migration + ['second_approver_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.primary_db_node_id', drNode('ch-lug-01')->id);

    expect(TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('resolves a separate shard per legal entity of a cross-border group and rolls up non-personal aggregates only', function (): void {
    $tenant = drTenant();
    $holding = drLegalEntity($tenant, 'LUG', 'Lugano Holding SA', 'CH');
    $subsidiary = drLegalEntity($tenant, 'MIL', 'Milano S.r.l.', 'IT');

    drEnforce($tenant, 'ch-lug', JurisdictionZone::StrictChOnly, $holding);
    drEnforce($tenant, 'it-mil', JurisdictionZone::StrictItOnly, $subsidiary);

    $resolver = app(SovereignDatabaseConnectionResolver::class);
    $holdingPolicy = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('legal_entity_id', $holding)->firstOrFail();
    $subsidiaryPolicy = TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('legal_entity_id', $subsidiary)->firstOrFail();

    expect($holdingPolicy->jurisdiction_zone)->toBe(JurisdictionZone::StrictChOnly)
        ->and($subsidiaryPolicy->jurisdiction_zone)->toBe(JurisdictionZone::StrictItOnly)
        ->and($resolver->connectionName($holdingPolicy->primaryDbNode))->toBe('pgsql_ch_lug')
        ->and($resolver->connectionName($subsidiaryPolicy->primaryDbNode))->toBe('pgsql_it_mil');

    $rollup = $resolver->aggregatedRollup($tenant->id, [$holding, $subsidiary], static fn ($connection, string $entityId): array => ['revenue' => $entityId === $holding ? 1200000.50 : 800000, 'invoices' => 42]);
    expect($rollup[$holding]['revenue'])->toBe(1200000.50)->and($rollup[$subsidiary]['invoices'])->toBe(42);

    expect(fn () => $resolver->aggregatedRollup($tenant->id, [$holding], static fn (): array => ['customer' => 'Mario Rossi']))->toThrow(DataResidencyException::class);

    $this->getJson('/api/v1/sovereignty/dashboard')->assertUnauthorized();
    drActAs($tenant);
    $scopes = collect($this->getJson('/api/v1/sovereignty/dashboard')->assertOk()->json('data.scopes'))->keyBy('legal_entity_name');

    expect($scopes['Lugano Holding SA']['jurisdiction_zone'])->toBe('Strict-CH-Only')
        ->and($scopes['Lugano Holding SA']['nodes']['postgres_primary']['node_code'])->toBe('ch-lug-01')
        ->and($scopes['Milano S.r.l.']['nodes']['s3_dms']['city'])->toBe('Milan');
});

it('lets a legal entity opt out of consolidation', function (): void {
    $tenant = drTenant();
    $entity = drLegalEntity($tenant, 'GVA', 'Geneva Private Bank SA', 'CH');
    drEnforce($tenant, 'ch-gva', JurisdictionZone::StrictChOnly, $entity);
    TenantDataResidencyPolicy::query()->withoutGlobalScopes()->where('legal_entity_id', $entity)->update(['allow_aggregated_non_pii_consolidation' => false]);

    expect(fn () => app(SovereignDatabaseConnectionResolver::class)->aggregatedRollup($tenant->id, [$entity], static fn (): array => ['revenue' => 1]))
        ->toThrow(CrossBorderResidencyViolationException::class);
});

it('names the offending node in the violation', function (): void {
    $policy = new TenantDataResidencyPolicy;
    $policy->jurisdiction_zone = JurisdictionZone::StrictChOnly;

    expect(fn () => $policy->assertNodeAllowedForJurisdiction(drNode('it-mil-01')))
        ->toThrow(CrossBorderResidencyViolationException::class, 'it-mil-01');

    $policy->assertNodeAllowedForJurisdiction(drNode('ch-zrh-01'));
});

it('keeps tenants apart: another tenant never reads the policy', function (): void {
    $a = drTenant();
    drEnforce($a, 'ch-zrh', JurisdictionZone::StrictChOnly);

    drActAs(Tenant::query()->whereKeyNot($a->id)->first() ?? drTenant());
    expect($this->getJson('/api/v1/sovereignty/policies')->assertOk()->json('data'))->toBe([]);
});
