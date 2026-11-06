<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Creates a tenant with a fixed VAT number, unique per test.
 */
function complianceTenant(string $name = 'Alfa'): Tenant
{
    return Tenant::create(['name' => $name, 'legal_name' => "{$name} Srl", 'vat_number' => '00811720580', 'fiscal_code' => '00811720580', 'country' => 'IT', 'default_currency' => 'EUR']);
}

/**
 * Authenticates a new member of the tenant and sends the tenant header on the next requests.
 */
function actingAsMember(Tenant $tenant, string $role = 'admin'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);

    return $user;
}
