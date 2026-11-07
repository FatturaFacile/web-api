<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\ChartOfAccount;

function verticalTenant(string $country = 'CH'): Tenant
{
    return Tenant::create([
        'name' => 'Vertical '.Str::random(6),
        'legal_name' => 'Vertical SA',
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);
}

/**
 * @param  list<string>|null  $permissions
 */
function verticalMember(Tenant $tenant, string $role = 'admin', ?array $permissions = null): User
{
    $user = User::create([
        'name' => ucfirst($role).' '.Str::random(4),
        'email' => Str::random(12).'@example.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, [
        'id' => (string) Str::uuid(),
        'role' => $role,
        'permissions' => $permissions === null ? null : json_encode($permissions),
    ]);

    return $user;
}

/**
 * Requests as a user, in a tenant.
 */
function verticalApi(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);
    test()->flushHeaders();

    return test()->withHeader('X-Tenant-Id', $tenant->id);
}

function verticalContact(Tenant $tenant, string $name = 'Cliente SA'): Contact
{
    app(TenantContext::class)->setTenant($tenant);

    $contact = new Contact;
    $contact->tenant_id = $tenant->id;
    $contact->fill(['type' => 'company', 'company_name' => $name, 'country' => 'CH', 'email' => Str::random(8).'@example.ch']);
    $contact->save();

    $address = new ContactAddress;
    $address->fill(['contact_id' => $contact->id, 'type' => 'billing', 'street' => 'Via Nassa', 'house_number' => '1', 'postal_code' => '6900', 'city' => 'Lugano', 'country' => 'CH']);
    $address->save();

    return $contact;
}

/**
 * Opens the fiscal year and a revenue account, so that the invoicing flow can post.
 */
function verticalLedger(Tenant $tenant, int $year = 2026): void
{
    app(TenantContext::class)->setTenant($tenant);
    app(OpenFiscalYearAction::class)->execute($tenant->id, $year);

    ChartOfAccount::query()->firstOrCreate(['tenant_id' => $tenant->id, 'account_code' => '3000'], [
        'name' => 'Revenue', 'account_class' => 'revenue', 'account_type' => 'revenue',
        'normal_balance' => 'credit', 'currency' => $tenant->default_currency,
    ]);
}
