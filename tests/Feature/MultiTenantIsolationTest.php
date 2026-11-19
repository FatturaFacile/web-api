<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\EiInboundLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

/**
 * A user of the tenant, as the application models it: the membership lives in the tenant_user pivot (users have no tenant_id).
 */
function isolationMember(Tenant $tenant, string $role): User
{
    $user = User::factory()->create();
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

describe('Multi-Tenant Isolation & Cross-Tenant Boundary Security', function () {
    it('prevents Tenant B from viewing, updating, or deleting invoices of Tenant A', function () {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        isolationMember($tenantA, 'admin');
        $userB = isolationMember($tenantB, 'admin');

        $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
        $invoiceA = SalesInvoice::factory()->create([
            'tenant_id' => $tenantA->id,
            'contact_id' => $contactA->id,
        ]);

        Sanctum::actingAs($userB);
        $this->withHeader('X-Tenant-Id', (string) $tenantB->id);

        // In multi-tenant scoped repositories, attempting to read another tenant's resource must yield 404 Not Found
        $this->getJson("/api/v1/sales/invoices/{$invoiceA->id}")->assertNotFound();

        $this->putJson("/api/v1/sales/invoices/{$invoiceA->id}", ['notes' => 'Tampered notes from unauthorized tenant'])->assertNotFound();

        $this->deleteJson("/api/v1/sales/invoices/{$invoiceA->id}")->assertNotFound();

        expect(SalesInvoice::withoutGlobalScopes()->whereKey($invoiceA->id)->value('notes'))->not->toBe('Tampered notes from unauthorized tenant');
    });

    it('denies access to the invoices of a tenant the user is not a member of', function () {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userB = isolationMember($tenantB, 'admin');

        Sanctum::actingAs($userB);

        $this->withHeader('X-Tenant-Id', (string) $tenantA->id)->getJson('/api/v1/sales/invoices')->assertForbidden();
    });

    it('denies access with 403 Forbidden when a user lacks required RBAC permissions', function () {
        $tenant = Tenant::factory()->create();
        $restrictedUser = isolationMember($tenant, 'viewer');

        Sanctum::actingAs($restrictedUser);
        $this->withHeader('X-Tenant-Id', (string) $tenant->id);

        // Must be forbidden due to lack of write/create permission
        $this->postJson('/api/v1/sales/invoices', ['invoice_type' => 'standard', 'currency' => 'EUR'])->assertForbidden();
    });

    it('isolates EiInboundLog records across tenants via BelongsToTenant global scope', function () {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $tenantContext = app(TenantContext::class);

        $tenantContext->setTenant($tenantA);
        $logA = EiInboundLog::create([
            'provider' => 'pec',
            'event_type' => 'INVOICE_RECEIVED',
            'fingerprint' => hash('sha256', 'tenant-a-inbound-log'),
            'status' => 'processed',
        ]);

        $tenantContext->setTenant($tenantB);
        $logB = EiInboundLog::create([
            'provider' => 'pec',
            'event_type' => 'INVOICE_RECEIVED',
            'fingerprint' => hash('sha256', 'tenant-b-inbound-log'),
            'status' => 'processed',
        ]);

        // Under Tenant A context, only Tenant A's EiInboundLog is visible
        $tenantContext->setTenant($tenantA);
        expect(EiInboundLog::query()->pluck('id')->all())->toBe([$logA->id])
            ->and(EiInboundLog::query()->find($logB->id))->toBeNull();

        // Under Tenant B context, only Tenant B's EiInboundLog is visible
        $tenantContext->setTenant($tenantB);
        expect(EiInboundLog::query()->pluck('id')->all())->toBe([$logB->id])
            ->and(EiInboundLog::query()->find($logA->id))->toBeNull();

        $tenantContext->clear();
    });
});
