<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Observability\Connectors\TlsCertificateInspector;

const OBS_ADMIN_API = '/api/v1/admin/observability';

/**
 * A platform administrator (no tenant context: the admin routes work across tenants).
 */
function obsPlatformAdmin(): User
{
    $user = User::create(['name' => 'Platform Admin', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->forceFill(['is_platform_admin' => true])->save();
    Sanctum::actingAs($user);

    return $user;
}

/**
 * A member of a company who is not a platform administrator; the tenant header and context are set for the tenant-facing routes.
 */
function obsMember(object $test, string $role = 'accountant'): User
{
    $tenant = Tenant::create(['name' => 'Osserva '.Str::random(5), 'legal_name' => 'Osserva Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    $user = User::create(['name' => ucfirst($role), 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', $tenant->id);
    app(TenantContext::class)->setTenant($tenant);
    $test->tenant = $tenant;

    return $user;
}

/**
 * The TLS inspector never opens a socket in tests.
 */
function obsFakeTls(?int $days = 90): void
{
    app()->instance(TlsCertificateInspector::class, new class($days) implements TlsCertificateInspector
    {
        public function __construct(private readonly ?int $days) {}

        public function daysRemaining(string $host, int $port = 443, float $timeoutSeconds = 5.0): ?int
        {
            return $this->days;
        }
    });
}

/**
 * @param  array<string, mixed>  $overrides
 */
function obsSlowQuery(array $overrides = []): void
{
    DB::table('observability_slow_queries')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => null, 'kind' => 'slow', 'fingerprint' => str_repeat('a', 40),
        'sql_text' => 'select * from invoices where tenant_id = ?', 'duration_ms' => 300, 'execution_count' => 1, 'connection_name' => 'pgsql',
        'trace_id' => str_repeat('b', 32), 'origin_type' => 'route', 'origin' => 'GET /api/v1/invoices', 'plan_status' => 'skipped', 'plan' => null,
        'suggested_indexes' => null, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function obsEndpointSample(array $overrides = []): void
{
    DB::table('observability_endpoint_samples')->insert(array_merge([
        'id' => (string) Str::uuid7(), 'tenant_id' => null, 'method' => 'GET', 'route' => '/api/v1/invoices', 'status_code' => 200,
        'duration_ms' => 100, 'db_query_count' => 5, 'db_time_ms' => 40, 'trace_id' => str_repeat('c', 32), 'captured_at' => now(),
    ], $overrides));
}
