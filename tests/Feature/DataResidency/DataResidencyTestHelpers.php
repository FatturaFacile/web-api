<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SovereignRegionNodeSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\DataResidency\Application\Commands\ConfigureByokOrHyokEnvelopeCommand;
use Modules\DataResidency\Application\Commands\EnforceGeoShardingAndRouteTenantStorageCommand;
use Modules\DataResidency\Application\Handlers\ConfigureByokOrHyokEnvelopeHandler;
use Modules\DataResidency\Application\Handlers\EnforceGeoShardingAndRouteTenantStorageHandler;
use Modules\DataResidency\Domain\Enums\JurisdictionZone;
use Modules\DataResidency\Domain\Enums\KeyManagementMode;
use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;
use Modules\DataResidency\Infrastructure\Models\SovereignRegionNode;
use Modules\DataResidency\Infrastructure\Models\TenantDataResidencyPolicy;

/**
 * Builders of the data residency tests. The sovereign catalogue is seeded by the production seeder, so the tests run on
 * the same Swiss and Italian nodes the platform ships.
 */
function drSeedCatalogue(): void
{
    (new SovereignRegionNodeSeeder)->run();
}

function drTenant(): Tenant
{
    return Tenant::create([
        'name' => 'Sovereign '.Str::random(5),
        'legal_name' => 'Sovereign SA',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
}

/**
 * Acts as a member of the tenant with the role and the membership permissions.
 *
 * @param  list<string>  $permissions
 */
function drActAs(Tenant $tenant, string $role = 'owner', array $permissions = []): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    return $user;
}

function drNode(string $code): SovereignRegionNode
{
    return SovereignRegionNode::query()->withoutGlobalScopes()->where('node_code', $code)->firstOrFail();
}

/**
 * The node ids of the seven services of a site (`ch-zrh`, `it-mil`...) in the payload shape of the policy API.
 *
 * @return array<string, mixed>
 */
function drSitePayload(string $site, JurisdictionZone $zone, ?string $entityId = null): array
{
    return [
        'legal_entity_id' => $entityId,
        'jurisdiction_zone' => $zone->value,
        'primary_db_node_id' => drNode("{$site}-01")->id,
        'replica_db_node_ids' => [drNode("{$site}-02")->id],
        's3_storage_node_id' => drNode("{$site}-s3-01")->id,
        'vector_db_node_id' => drNode("{$site}-vec-01")->id,
        'ai_inference_node_id' => drNode("{$site}-ai-01")->id,
        'backup_node_id' => drNode("{$site}-bkp-01")->id,
        'queue_node_id' => drNode("{$site}-q-01")->id,
    ];
}

function drEnforce(Tenant $tenant, string $site, JurisdictionZone $zone, ?string $entityId = null, bool $strictEgress = true): TenantDataResidencyPolicy
{
    $p = drSitePayload($site, $zone, $entityId);

    return app(EnforceGeoShardingAndRouteTenantStorageHandler::class)->handle(new EnforceGeoShardingAndRouteTenantStorageCommand(
        $tenant->id, $entityId, (string) Str::uuid(), $zone, $p['primary_db_node_id'], $p['replica_db_node_ids'], $p['s3_storage_node_id'],
        $p['vector_db_node_id'], $p['ai_inference_node_id'], $p['backup_node_id'], $p['queue_node_id'], $strictEgress,
    ));
}

function drLegalEntity(Tenant $tenant, string $code, string $legalName, string $country): string
{
    $id = (string) Str::uuid();
    DB::table('companies')->insert([
        'id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'legal_name' => $legalName, 'country' => $country,
        'currency' => $country === 'CH' ? 'CHF' : 'EUR', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/**
 * A key envelope through the production handler. The default is the platform KMS (no network); pass a provider and Http::fake()
 * responses to exercise a customer-managed one.
 *
 * @param  array<string, mixed>  $credentials
 */
function drEnvelope(Tenant $tenant, KeyManagementMode $mode = KeyManagementMode::PlatformManaged, KmsProviderType $provider = KmsProviderType::Platform, ?string $entityId = null, string $keyUri = 'platform://default', array $credentials = [], int $ttl = 300): CustomerManagedKeyEnvelope
{
    $result = app(ConfigureByokOrHyokEnvelopeHandler::class)->handle(new ConfigureByokOrHyokEnvelopeCommand(
        $tenant->id, $entityId, (string) Str::uuid(), $mode, $provider, $keyUri, null, $credentials, $ttl,
    ));

    return $result['envelope'] ?? throw new RuntimeException('The envelope was not persisted.');
}
