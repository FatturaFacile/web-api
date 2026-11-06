<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Associations\Services\AreaTokenService;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;

function associationsSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Polisportiva Alfa', 'legal_name' => 'Polisportiva Alfa', 'country' => 'IT']);
    $test->otherTenant = Tenant::create(['name' => 'Circolo Beta', 'legal_name' => 'Circolo Beta', 'country' => 'IT']);

    app(TenantContext::class)->setTenant($test->tenant);
}

function associationsTearDown(): void
{
    app(TenantContext::class)->clear();
}

/**
 * Acts as a user of the company with the given role.
 *
 * @param  list<string>  $permissions
 */
function actAs(object $test, string $role, array $permissions = [], ?Tenant $tenant = null): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode($permissions)]);

    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', $tenant->id);
    app(TenantContext::class)->setTenant($tenant);

    return $user;
}

/**
 * Runs the callback for a tenant, or with no tenant at all to write generic rows.
 */
function inTenant(?Tenant $tenant, Closure $callback): mixed
{
    $context = app(TenantContext::class);

    if ($tenant !== null) {
        return $context->runWithTenant($tenant, $callback);
    }

    $previous = $context->isExplicit() ? $context->getTenant() : null;
    $context->setTenant(null);

    try {
        return $callback();
    } finally {
        $previous !== null ? $context->setTenant($previous) : $context->clear();
    }
}

/**
 * The personal token of the member area of a contact.
 */
function areaToken(Contact $contact): string
{
    return app(PortalTokenService::class)->createToken($contact, AreaTokenService::TokenType)['token'];
}

function areaHeaders(string $token): array
{
    return ['X-Area-Token' => $token, 'Accept' => 'application/json'];
}

/**
 * The ledger needs an open period containing the payment date.
 */
function openFiscalPeriod(Tenant $tenant, ?CarbonImmutable $day = null): FiscalPeriod
{
    $day ??= CarbonImmutable::today();

    return inTenant($tenant, static function () use ($tenant, $day): FiscalPeriod {
        $year = FiscalYear::create([
            'tenant_id' => $tenant->id,
            'year_code' => 'FY'.$day->year,
            'start_date' => $day->startOfYear()->toDateString(),
            'end_date' => $day->endOfYear()->toDateString(),
            'status' => 'open',
        ]);

        return FiscalPeriod::create([
            'tenant_id' => $tenant->id,
            'fiscal_year_id' => $year->id,
            'period_number' => 1,
            'period_name' => (string) $day->year,
            'start_date' => $day->startOfYear()->toDateString(),
            'end_date' => $day->endOfYear()->toDateString(),
            'status' => 'open',
        ]);
    });
}
