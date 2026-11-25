<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\CpqConfiguratorDemoSeeder;
use Database\Seeders\CpqConfiguratorLocalizationSeeder;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CpqConfigurator\Models\CpqModel;

/**
 * Builders of the CPQ configurator tests (Mod 230): a company with the seeded demo models and a signed-in member.
 */
function cpqSetUp(object $test, string $role = 'sales_manager'): void
{
    $test->tenant = cpqTenant('Configuratori Srl');
    cpqInTenant($test->tenant, fn () => (new CpqConfiguratorDemoSeeder)->seedTenant());
    (new CpqConfiguratorLocalizationSeeder)->run();
    $test->user = cpqActor($test, $role);
}

function cpqTenant(string $name): Tenant
{
    return Tenant::create(['name' => $name, 'legal_name' => $name, 'country' => 'IT', 'default_currency' => 'EUR']);
}

function cpqInTenant(Tenant $tenant, callable $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

function cpqActor(object $test, string $role, ?Tenant $tenant = null): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', $tenant->id);
    app(TenantContext::class)->setTenant($tenant);

    return $user;
}

function cpqModel(object $test, string $code = 'PUMP-SKID'): CpqModel
{
    return cpqInTenant($test->tenant, fn () => CpqModel::query()->where('code', $code)->firstOrFail());
}

/**
 * A complete, valid selection of the pump skid (water, safe area, 400 V three-phase, direct-on-line panel).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cpqPumpSelections(array $overrides = []): array
{
    return array_merge([
        'destination_country' => 'IT', 'fluid' => 'WATER', 'atex_zone' => 'NONE', 'flow_rate' => 40, 'head' => 30, 'material' => 'AISI_304',
        'voltage' => 'V400_3P', 'control_panel' => 'BASIC', 'cable_length_m' => 10, 'test_level' => 'STANDARD', 'warranty_years' => 2,
    ], $overrides);
}

function cpqUrl(string $path = ''): string
{
    return '/api/v1/cpq-configurator'.$path;
}
