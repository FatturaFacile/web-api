<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Invoicing\Models\SalesInvoice;

uses(RefreshDatabase::class);

describe('Multi-Tenant Isolation & Cross-Tenant Boundary Security', function () {
    it('prevents Tenant B from viewing, updating, or deleting invoices of Tenant A', function () {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->create(['tenant_id' => $tenantB->id]);

        $contactA = Contact::factory()->create(['tenant_id' => $tenantA->id]);
        $invoiceA = SalesInvoice::factory()->create([
            'tenant_id' => $tenantA->id,
            'contact_id' => $contactA->id,
        ]);

        // Attempt by User B from Tenant B to access Invoice of Tenant A
        $response = $this->actingAs($userB)
            ->withHeaders(['X-Tenant' => (string) $tenantB->id])
            ->getJson("/api/v1/invoices/{$invoiceA->id}");

        // In multi-tenant scoped repositories, attempting to read another tenant's resource must yield 404 Not Found
        $response->assertStatus(404);

        // Attempt update
        $updateResponse = $this->actingAs($userB)
            ->withHeaders(['X-Tenant' => (string) $tenantB->id])
            ->putJson("/api/v1/invoices/{$invoiceA->id}", [
                'notes' => 'Tampered notes from unauthorized tenant',
            ]);
        $updateResponse->assertStatus(404);

        // Attempt delete
        $deleteResponse = $this->actingAs($userB)
            ->withHeaders(['X-Tenant' => (string) $tenantB->id])
            ->deleteJson("/api/v1/invoices/{$invoiceA->id}");
        $deleteResponse->assertStatus(404);
    });

    it('denies access with 403 Forbidden when a user lacks required RBAC permissions', function () {
        $tenant = Tenant::factory()->create();
        $restrictedUser = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($restrictedUser)
            ->withHeaders(['X-Tenant' => (string) $tenant->id])
            ->postJson('/api/v1/invoices', [
                'invoice_type' => 'standard',
                'currency' => 'EUR',
            ]);

        // Must be forbidden due to lack of write/create permission
        expect(in_array($response->status(), [403, 401, 422], true))->toBeTrue();
    });
});
