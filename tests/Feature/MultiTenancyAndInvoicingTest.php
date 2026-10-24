<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Tenant 1 (IT)
    $this->tenantIt = Tenant::create([
        'name' => 'FatturaFacile Italia SRL',
        'legal_name' => 'FatturaFacile Italia Società a Responsabilità Limitata',
        'fiscal_code' => '00811720580',
        'vat_number' => '00811720580',
        'country' => 'IT',
        'default_currency' => 'EUR',
        'iban' => 'IT60X0542811101000000123456',
        'sdi_code' => 'M5UXCR1',
    ]);

    // Tenant 2 (CH)
    $this->tenantCh = Tenant::create([
        'name' => 'FatturaFacile Schweiz AG',
        'legal_name' => 'FatturaFacile Schweiz Aktiengesellschaft',
        'uid_number' => 'CHE-123.456.789',
        'country' => 'CH',
        'default_currency' => 'CHF',
        'iban' => 'CH9300762011623852957',
        'qr_iban' => 'CH4431999123000889012',
    ]);

    $this->user = User::create([
        'name' => 'Leonardo Da Vinci',
        'email' => 'leonardo@fatturafacile.com',
        'password' => bcrypt('secret123'),
        'locale' => 'it',
    ]);

    // Attach user to both tenants
    $this->user->tenants()->attach($this->tenantIt->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    $this->user->tenants()->attach($this->tenantCh->id, ['id' => (string) Str::uuid(), 'role' => 'owner']);

    Sanctum::actingAs($this->user);
});

test('it enforces multi-tenant data isolation on invoice listing', function (): void {
    // Create contact & invoice in Tenant IT
    app(TenantContext::class)->setTenant($this->tenantIt);
    $contactIt = Contact::create([
        'tenant_id' => $this->tenantIt->id,
        'company_name' => 'Cliente Milano SpA',
        'country' => 'IT',
        'vat_number' => '00811720580',
    ]);

    $invoiceIt = Invoice::create([
        'tenant_id' => $this->tenantIt->id,
        'contact_id' => $contactIt->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'IT-2026-0001',
        'date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_net' => 1000.00,
        'total_tax' => 220.00,
        'total_gross' => 1220.00,
        'jurisdiction' => 'IT',
    ]);

    // Create invoice in Tenant CH
    app(TenantContext::class)->setTenant($this->tenantCh);
    $contactCh = Contact::create([
        'tenant_id' => $this->tenantCh->id,
        'company_name' => 'Kunde Zürich GmbH',
        'country' => 'CH',
    ]);

    $invoiceCh = Invoice::create([
        'tenant_id' => $this->tenantCh->id,
        'contact_id' => $contactCh->id,
        'invoice_type' => 'ch_qr_bill',
        'invoice_number' => 'CH-2026-0001',
        'date' => now()->toDateString(),
        'currency' => 'CHF',
        'total_net' => 2000.00,
        'total_tax' => 162.00,
        'total_gross' => 2162.00,
        'jurisdiction' => 'CH',
    ]);

    // Requesting as Tenant IT should ONLY show IT-2026-0001
    $responseIt = $this->withHeader('X-Tenant-ID', $this->tenantIt->id)
        ->getJson('/api/v1/sales/invoices');

    $responseIt->assertOk()
        ->assertJsonPath('data.0.invoice_number', 'IT-2026-0001')
        ->assertJsonCount(1, 'data');

    // Requesting as Tenant CH should ONLY show CH-2026-0001
    $responseCh = $this->withHeader('X-Tenant-ID', $this->tenantCh->id)
        ->getJson('/api/v1/sales/invoices');

    $responseCh->assertOk()
        ->assertJsonPath('data.0.invoice_number', 'CH-2026-0001')
        ->assertJsonCount(1, 'data');
});

test('it creates a swiss invoice and automatically applies 0.05 chf rounding', function (): void {
    app(TenantContext::class)->setTenant($this->tenantCh);
    $contact = Contact::create([
        'tenant_id' => $this->tenantCh->id,
        'company_name' => 'Swiss Client AG',
        'country' => 'CH',
    ]);

    $payload = [
        'contact_id' => $contact->id,
        'invoice_type' => 'ch_qr_bill',
        'invoice_number' => 'INV-CH-100',
        'date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency' => 'CHF',
        'jurisdiction' => 'CH',
        'items' => [
            [
                'description' => 'Enterprise Architecture Consulting',
                'quantity' => 1,
                'unit_price' => 100.43,
                'tax_rate' => 0.0, // 100.43 CHF -> rounds to 100.45 CHF (+0.02 CHF rounding)
            ],
        ],
    ];

    $response = $this->withHeader('X-Tenant-ID', $this->tenantCh->id)
        ->postJson('/api/v1/sales/invoices', $payload);

    $response->assertCreated()
        ->assertJsonPath('data.invoice_number', 'INV-CH-100')
        ->assertJsonPath('data.total_net', 100.43)
        ->assertJsonPath('data.total_gross', 100.45)
        ->assertJsonPath('data.rounding_amount', 0.02);
});

test('it generates sdi xml for italian invoice', function (): void {
    app(TenantContext::class)->setTenant($this->tenantIt);
    $contact = Contact::create([
        'tenant_id' => $this->tenantIt->id,
        'company_name' => 'Pubblica Amministrazione Roma',
        'country' => 'IT',
        'vat_number' => '00811720580',
        'sdi_code' => 'UF1234',
    ]);

    $contact->addresses()->create([
        'type' => 'billing',
        'street' => 'Via del Corso',
        'house_number' => '100',
        'postal_code' => '00186',
        'city' => 'Roma',
        'country' => 'IT',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenantIt->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'sdi_electronic',
        'invoice_number' => 'FATT-2026-99',
        'date' => now()->toDateString(),
        'currency' => 'EUR',
        'total_net' => 500.00,
        'total_tax' => 110.00,
        'total_gross' => 610.00,
        'jurisdiction' => 'IT',
        'split_payment' => true,
    ]);

    $invoice->items()->create([
        'description' => 'Servizi Cloud Software',
        'quantity' => 1,
        'unit_price' => 500.00,
        'tax_rate' => 22.00,
        'line_total' => 500.00,
    ]);

    $response = $this->withHeader('X-Tenant-ID', $this->tenantIt->id)
        ->postJson("/api/v1/sales/invoices/{$invoice->id}/generate-sdi-xml");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure(['data' => ['invoice_id', 'file_name', 'sdi_status', 'xml_preview']]);

    $xml = $response->json('data.xml_preview');
    expect($xml)->toContain('<FatturaElettronica')
        ->and($xml)->toContain('<FatturaElettronicaHeader>')
        ->and($xml)->toContain('<DatiTrasmissione>')
        ->and($xml)->toContain('<EsigibilitaIVA>S</EsigibilitaIVA>'); // Split Payment
});

test('it generates swiss qr-bill svg and scor reference', function (): void {
    app(TenantContext::class)->setTenant($this->tenantCh);
    $contact = Contact::create([
        'tenant_id' => $this->tenantCh->id,
        'company_name' => 'Boutique Lugano SA',
        'country' => 'CH',
    ]);

    $contact->addresses()->create([
        'type' => 'billing',
        'street' => 'Via Nassa',
        'house_number' => '22',
        'postal_code' => '6900',
        'city' => 'Lugano',
        'country' => 'CH',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenantCh->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'ch_qr_bill',
        'invoice_number' => 'QR-9001',
        'date' => now()->toDateString(),
        'currency' => 'CHF',
        'total_net' => 450.00,
        'total_tax' => 36.45,
        'total_gross' => 486.45,
        'jurisdiction' => 'CH',
    ]);

    $response = $this->withHeader('X-Tenant-ID', $this->tenantCh->id)
        ->postJson("/api/v1/sales/invoices/{$invoice->id}/generate-qr-bill");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.currency', 'CHF')
        ->assertJsonPath('data.qr_iban', 'CH4431999123000889012');

    $scor = $response->json('data.scor_reference');
    expect(strlen($scor))->toBe(27);

    // Download SVG
    $download = $this->withHeader('X-Tenant-ID', $this->tenantCh->id)
        ->get("/api/v1/sales/invoices/{$invoice->id}/download-qr-bill");

    $download->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml');
});

test('it handles time attendance clock in and clock out lifecycle', function (): void {
    $clockInResponse = $this->withHeader('X-Tenant-ID', $this->tenantIt->id)
        ->postJson('/api/v1/time-attendance/clock-in', [
            'latitude' => 45.4642,
            'longitude' => 9.1900,
            'notes' => 'Clock-in from Milan Headquarter',
        ]);

    $clockInResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user_id', $this->user->id);

    // Clocking in again while active should trigger 409 Conflict Problem Details
    $conflictResponse = $this->withHeader('X-Tenant-ID', $this->tenantIt->id)
        ->postJson('/api/v1/time-attendance/clock-in');

    $conflictResponse->assertStatus(409)
        ->assertJsonPath('title', 'Already Clocked In');

    // Clock out
    $clockOutResponse = $this->withHeader('X-Tenant-ID', $this->tenantIt->id)
        ->postJson('/api/v1/time-attendance/clock-out', [
            'break_minutes' => 45,
            'notes' => 'Shift ended smoothly',
        ]);

    $clockOutResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.break_minutes', 45);
});
