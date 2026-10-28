<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\LookupCategory;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\ContactPolicy;
use App\Policies\DeliveryNotePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\QuotePolicy;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Tenant A
    $this->tenantA = Tenant::create([
        'name' => 'Tenant A Craftsmen SRL',
        'legal_name' => 'Tenant A Craftsmen SRL',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    // User A
    $this->userA = User::create([
        'name' => 'User Tenant A',
        'email' => 'user.a@tenanta.com',
        'password' => bcrypt('password123'),
        'locale' => 'it',
    ]);
    $this->userA->tenants()->attach($this->tenantA->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    // Tenant B
    $this->tenantB = Tenant::create([
        'name' => 'Tenant B Plumbing SA',
        'legal_name' => 'Tenant B Plumbing SA',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    // User B
    $this->userB = User::create([
        'name' => 'User Tenant B',
        'email' => 'user.b@tenantb.ch',
        'password' => bcrypt('password123'),
        'locale' => 'de',
    ]);
    $this->userB->tenants()->attach($this->tenantB->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    // Default to Tenant A
    app(TenantContext::class)->setTenant($this->tenantA);
    Sanctum::actingAs($this->userA);
});

/*
|--------------------------------------------------------------------------
| CRIT-01: TenantScope Strict Isolation Tests
|--------------------------------------------------------------------------
*/

test('CRIT-01: operational models return 0 rows when tenant context is missing', function (): void {
    // Create an invoice under Tenant A
    $contact = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Acme Test Client',
    ]);

    Invoice::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2026-0001',
        'date' => '2026-10-28',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'payment_status' => 'unpaid',
    ]);

    // Clear tenant context
    app(TenantContext::class)->setTenant(null);

    // Queries without tenant context must yield 0 results (1 = 0 condition applied)
    expect(Invoice::count())->toBe(0)
        ->and(Contact::count())->toBe(0)
        ->and(Quote::count())->toBe(0);
});

test('CRIT-01: operational models never leak records from other tenants or records with null tenant_id', function (): void {
    // Create record for Tenant A
    $contactA = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Client A',
    ]);

    $invoiceA = Invoice::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contactA->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-A-001',
        'date' => '2026-10-28',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'payment_status' => 'unpaid',
    ]);

    // Switch to Tenant B
    app(TenantContext::class)->setTenant($this->tenantB);

    $contactB = Contact::create([
        'tenant_id' => $this->tenantB->id,
        'type' => 'client',
        'country' => 'CH',
        'company_name' => 'Client B',
    ]);

    $invoiceB = Invoice::create([
        'tenant_id' => $this->tenantB->id,
        'contact_id' => $contactB->id,
        'invoice_type' => 'ch_qr_bill',
        'invoice_number' => 'INV-B-001',
        'date' => '2026-10-28',
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'payment_status' => 'unpaid',
    ]);

    // Under Tenant B context, only Tenant B invoices should be visible
    $visibleInvoices = Invoice::all();
    expect($visibleInvoices)->toHaveCount(1)
        ->and($visibleInvoices->first()->id)->toBe($invoiceB->id)
        ->and($visibleInvoices->first()->invoice_number)->toBe('INV-B-001');

    // Switch back to Tenant A
    app(TenantContext::class)->setTenant($this->tenantA);

    $visibleInvoicesA = Invoice::all();
    expect($visibleInvoicesA)->toHaveCount(1)
        ->and($visibleInvoicesA->first()->id)->toBe($invoiceA->id)
        ->and($visibleInvoicesA->first()->invoice_number)->toBe('INV-A-001');
});

test('CRIT-01: shared lookup tables allow null tenant_id records to remain globally accessible', function (): void {
    // Shared lookup categories (tenant_id = null)
    LookupCategory::create([
        'code' => 'vat_rates_shared',
        'name' => 'Shared VAT Rates',
        'is_system' => true,
        'tenant_id' => null,
    ]);

    // With active tenant context, shared lookup is visible
    expect(LookupCategory::where('code', 'vat_rates_shared')->exists())->toBeTrue();

    // Even without tenant context, shared lookup remains queryable
    app(TenantContext::class)->setTenant(null);
    expect(LookupCategory::where('code', 'vat_rates_shared')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| CRIT-02: Tenant Injection Prevention Tests
|--------------------------------------------------------------------------
*/

test('CRIT-02: tenant_id is stripped from fillable in operational models', function (): void {
    $models = [
        new Invoice,
        new Quote,
        new DeliveryNote,
        new SalesOrder,
        new Contact,
        new Payment,
    ];

    foreach ($models as $model) {
        expect($model->getFillable())->not->toContain('tenant_id');
    }
});

test('CRIT-02: POST sales/invoices ignores injected tenant_id and binds to authenticated tenant', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Legitimate Client',
    ]);

    $maliciousTenantId = (string) Str::uuid();

    $payload = [
        'tenant_id' => $maliciousTenantId, // Attempted tenant override
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-SEC-001',
        'date' => '2026-10-28',
        'due_date' => '2026-11-28',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'items' => [
            [
                'description' => 'Security Audit Service',
                'quantity' => 1,
                'unit_price' => 1500.00,
                'tax_rate' => 22.00,
            ],
        ],
    ];

    $response = $this->withHeader('X-Tenant-ID', $this->tenantA->id)
        ->postJson('/api/v1/sales/invoices', $payload);

    $response->assertCreated();

    $createdInvoice = Invoice::where('invoice_number', 'INV-SEC-001')->firstOrFail();

    // Must be bound to Tenant A, never the injected malicious tenant ID
    expect($createdInvoice->tenant_id)->toBe($this->tenantA->id)
        ->and($createdInvoice->tenant_id)->not->toBe($maliciousTenantId);
});

test('CRIT-02: POST sales/quotes ignores injected tenant_id and binds to authenticated tenant', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Quote Client',
    ]);

    $maliciousTenantId = (string) Str::uuid();

    $payload = [
        'tenant_id' => $maliciousTenantId,
        'contact_id' => $contact->id,
        'quote_number' => 'QUO-SEC-001',
        'date' => '2026-10-28',
        'valid_until' => '2026-11-28',
        'currency' => 'EUR',
        'items' => [
            [
                'description' => 'Heat pump installation quote',
                'quantity' => 1,
                'unit_price' => 4500.00,
                'tax_rate' => 10.00,
            ],
        ],
    ];

    $response = $this->withHeader('X-Tenant-ID', $this->tenantA->id)
        ->postJson('/api/v1/sales/quotes', $payload);

    $response->assertCreated();

    $quoteId = $response->json('data.id');
    $createdQuote = Quote::findOrFail($quoteId);

    expect($createdQuote->tenant_id)->toBe($this->tenantA->id)
        ->and($createdQuote->tenant_id)->not->toBe($maliciousTenantId);
});

/*
|--------------------------------------------------------------------------
| CRIT-03: Authorization Layer & Policy Enforcement Tests
|--------------------------------------------------------------------------
*/

test('CRIT-03: cross-tenant access to invoices is strictly forbidden by policy and controller', function (): void {
    // Create resource in Tenant B
    app(TenantContext::class)->setTenant($this->tenantB);
    $contactB = Contact::create([
        'tenant_id' => $this->tenantB->id,
        'type' => 'client',
        'country' => 'CH',
        'company_name' => 'Swiss Client',
    ]);
    $invoiceB = Invoice::create([
        'tenant_id' => $this->tenantB->id,
        'contact_id' => $contactB->id,
        'invoice_type' => 'ch_qr_bill',
        'invoice_number' => 'INV-CH-001',
        'date' => '2026-10-28',
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'payment_status' => 'unpaid',
    ]);

    // Now switch back to User A (Tenant A)
    app(TenantContext::class)->setTenant($this->tenantA);
    Sanctum::actingAs($this->userA);

    // Direct policy evaluation must reject User A for Tenant B's invoice
    $policy = new InvoicePolicy;
    expect($policy->view($this->userA, $invoiceB))->toBeFalse()
        ->and($policy->update($this->userA, $invoiceB))->toBeFalse()
        ->and($policy->delete($this->userA, $invoiceB))->toBeFalse()
        ->and($policy->recordPayment($this->userA, $invoiceB))->toBeFalse()
        ->and($policy->generateQrBill($this->userA, $invoiceB))->toBeFalse();

    // API request from User A targeting Tenant B's invoice must fail (404 via TenantScope or 403 via Policy)
    $response = $this->withHeader('X-Tenant-ID', $this->tenantA->id)
        ->getJson("/api/v1/sales/invoices/{$invoiceB->id}");

    expect($response->status())->toBeIn([403, 404]);
});

test('CRIT-03: cross-tenant access to quotes, delivery notes, and contacts is denied', function (): void {
    // Tenant B resources
    app(TenantContext::class)->setTenant($this->tenantB);
    $contactB = Contact::create([
        'tenant_id' => $this->tenantB->id,
        'type' => 'client',
        'country' => 'CH',
        'company_name' => 'Tenant B Contact',
    ]);
    $quoteB = Quote::create([
        'tenant_id' => $this->tenantB->id,
        'contact_id' => $contactB->id,
        'quote_number' => 'QUO-CH-001',
        'date' => '2026-10-28',
        'currency' => 'CHF',
        'status' => 'draft',
    ]);
    $ddtB = DeliveryNote::create([
        'tenant_id' => $this->tenantB->id,
        'contact_id' => $contactB->id,
        'ddt_number' => 'DDT-CH-001',
        'date' => '2026-10-28',
    ]);

    // Switch to User A
    app(TenantContext::class)->setTenant($this->tenantA);
    Sanctum::actingAs($this->userA);

    $quotePolicy = new QuotePolicy;
    expect($quotePolicy->view($this->userA, $quoteB))->toBeFalse()
        ->and($quotePolicy->update($this->userA, $quoteB))->toBeFalse()
        ->and($quotePolicy->delete($this->userA, $quoteB))->toBeFalse()
        ->and($quotePolicy->sign($this->userA, $quoteB))->toBeFalse()
        ->and($quotePolicy->convert($this->userA, $quoteB))->toBeFalse();

    $ddtPolicy = new DeliveryNotePolicy;
    expect($ddtPolicy->view($this->userA, $ddtB))->toBeFalse()
        ->and($ddtPolicy->delete($this->userA, $ddtB))->toBeFalse()
        ->and($ddtPolicy->invoice($this->userA, $ddtB))->toBeFalse();

    $contactPolicy = new ContactPolicy;
    expect($contactPolicy->view($this->userA, $contactB))->toBeFalse()
        ->and($contactPolicy->update($this->userA, $contactB))->toBeFalse()
        ->and($contactPolicy->delete($this->userA, $contactB))->toBeFalse();
});

test('CRIT-03: policies protect lifecycle states (paid invoice, accepted quote, invoiced delivery note)', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Lifecycle Client',
    ]);

    // 1. Paid invoice cannot be modified
    $paidInvoice = Invoice::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-PAID-001',
        'date' => '2026-10-28',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'payment_status' => 'paid',
        'total_paid' => 500.00,
        'total_gross' => 500.00,
    ]);

    $invoicePolicy = new InvoicePolicy;
    expect($invoicePolicy->update($this->userA, $paidInvoice))->toBeFalse()
        ->and($invoicePolicy->delete($this->userA, $paidInvoice))->toBeFalse();

    // 2. Accepted quote cannot be modified or deleted
    $acceptedQuote = Quote::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contact->id,
        'quote_number' => 'QUO-ACC-001',
        'date' => '2026-10-28',
        'currency' => 'EUR',
        'status' => 'accepted',
    ]);

    $quotePolicy = new QuotePolicy;
    expect($quotePolicy->update($this->userA, $acceptedQuote))->toBeFalse()
        ->and($quotePolicy->delete($this->userA, $acceptedQuote))->toBeFalse();

    // 3. Invoiced delivery note cannot be deleted
    $invoicedDdt = DeliveryNote::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contact->id,
        'ddt_number' => 'DDT-INV-001',
        'date' => '2026-10-28',
        'status' => 'invoiced',
    ]);

    $ddtPolicy = new DeliveryNotePolicy;
    expect($ddtPolicy->delete($this->userA, $invoicedDdt))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| CRIT-04: Fiscal Compliance Deletion Restrictions Tests
|--------------------------------------------------------------------------
*/

test('CRIT-04: contact with invoices cannot be deleted', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Protected Client',
    ]);

    Invoice::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-PROT-001',
        'date' => '2026-10-28',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'payment_status' => 'unpaid',
    ]);

    $contactPolicy = new ContactPolicy;
    expect($contactPolicy->delete($this->userA, $contact))->toBeFalse();

    // Attempting to delete via API must be rejected with 403 Forbidden
    $response = $this->withHeader('X-Tenant-ID', $this->tenantA->id)
        ->deleteJson("/api/v1/crm/contacts/{$contact->id}");

    $response->assertForbidden();
});

test('CRIT-04: invoice with recorded payments cannot be deleted', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenantA->id,
        'type' => 'client',
        'country' => 'IT',
        'company_name' => 'Paid Client',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenantA->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-PARTIAL-001',
        'date' => '2026-10-28',
        'currency' => 'EUR',
        'jurisdiction' => 'IT',
        'payment_status' => 'partially_paid',
        'total_gross' => 1000.00,
        'total_paid' => 400.00,
    ]);

    $invoicePolicy = new InvoicePolicy;
    expect($invoicePolicy->delete($this->userA, $invoice))->toBeFalse();
});
