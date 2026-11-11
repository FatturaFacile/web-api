<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\CRM\Models\Lead;

/**
 * Builders shared by the privacy tests (tenants, administrators, subjects). Passwords are generated at runtime.
 * Records are created in the context of their tenant, because the tenant trait forces tenant_id to the active one.
 */
final class PrivacyFixtures
{
    public static function tenant(string $name): Tenant
    {
        return Tenant::create(['name' => $name.' '.Str::random(4), 'legal_name' => $name.' SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    }

    public static function member(Tenant $tenant, string $role = 'owner'): User
    {
        $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::random(10).'@privacy-test.example', 'password' => bcrypt(Str::random(32)), 'locale' => 'en']);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    }

    /**
     * Authenticates the user for the tenant and returns the headers to send.
     *
     * @return array<string, string>
     */
    public static function actAs(User $user, Tenant $tenant): array
    {
        app(TenantContext::class)->setTenant($tenant);
        Sanctum::actingAs($user);

        return ['X-Tenant-Id' => (string) $tenant->id];
    }

    public static function contact(Tenant $tenant, string $company = 'Hans Meier Consulting'): Contact
    {
        return app(TenantContext::class)->runWithTenant($tenant, fn (): Contact => Contact::create([
            'tenant_id' => (string) $tenant->id,
            'company_name' => $company,
            'first_name' => 'Hans',
            'last_name' => 'Meier',
            'email' => Str::random(8).'@meier.example',
            'phone' => '+41791234567',
            'iban' => 'CH9300762011623852957',
            'type' => 'customer',
            'country' => 'CH',
        ]));
    }

    public static function invoice(Tenant $tenant, Contact $contact, string $date = '2026-05-01'): Invoice
    {
        return app(TenantContext::class)->runWithTenant($tenant, fn (): Invoice => Invoice::create([
            'tenant_id' => (string) $tenant->id,
            'contact_id' => $contact->id,
            'invoice_number' => 'INV-'.Str::random(6),
            'invoice_type' => 'standard',
            'date' => $date,
            'due_date' => $date,
            'currency' => 'CHF',
            'total_net' => '500.00',
            'total_tax' => '0.00',
            'total_gross' => '500.00',
            'payment_status' => 'paid',
        ]));
    }

    public static function lead(Tenant $tenant, ?string $updatedAt = null): Lead
    {
        $lead = app(TenantContext::class)->runWithTenant($tenant, fn (): Lead => Lead::factory()->create(['tenant_id' => (string) $tenant->id]));
        if ($updatedAt !== null) {
            Lead::withoutGlobalScopes()->whereKey($lead->id)->toBase()->update(['updated_at' => $updatedAt]);
        }

        return Lead::withoutGlobalScopes()->findOrFail($lead->id);
    }
}
