<?php

declare(strict_types=1);

use App\Actions\Sales\ConvertQuoteToOrder;
use App\Actions\Sales\ConvertToInvoice;
use App\Jobs\SendAccountStatementEmail;
use App\Jobs\SendDocumentEmailJob;
use App\Jobs\SendPaymentReminderEmail;
use App\Jobs\SendSubscriptionRenewalNotice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSdiMetadata;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Acme Fiscal Tenant',
        'legal_name' => 'Acme Fiscal Tenant SRL',
        'vat_number' => 'IT12345678901',
        'tax_code' => '12345678901',
        'sdi_code' => 'M5UXCR1',
        'status' => 'active',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::factory()->create([
        'email' => 'admin@acmefiscal.com',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->otherTenant = Tenant::create([
        'name' => 'Foreign Tenant',
        'legal_name' => 'Foreign Tenant SA',
        'vat_number' => 'IT99999999999',
        'tax_code' => '99999999999',
        'sdi_code' => 'XXXXXXX',
        'status' => 'active',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->otherUser = User::factory()->create([
        'email' => 'other@foreign.com',
    ]);
    $this->otherUser->tenants()->attach($this->otherTenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
});

test('CRIT-05: invoices transmitted to SDI cannot be updated or deleted', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Fiscal Client SRL',
        'type' => 'company',
        'vat_number' => 'IT11223344556',
        'email' => 'client@fiscal.com',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2026-0099',
        'date' => '2026-10-01',
        'payment_status' => 'unpaid',
        'total_net' => 100.0,
        'total_tax' => 22.0,
        'total_gross' => 122.0,
    ]);

    // When no SDI transmission has occurred, update and delete are permitted
    expect(Gate::forUser($this->user)->allows('update', $invoice))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('delete', $invoice))->toBeTrue();

    // Attach SDI metadata with 'delivered' status
    InvoiceSdiMetadata::create([
        'invoice_id' => $invoice->id,
        'sdi_status' => 'delivered',
        'sdi_identifier' => 'SDI-998877',
        'channel' => 'fatturapa',
    ]);

    $invoice->refresh();

    // Now update and delete must be denied by policy to preserve fiscal integrity
    expect(Gate::forUser($this->user)->allows('update', $invoice))->toBeFalse()
        ->and(Gate::forUser($this->user)->allows('delete', $invoice))->toBeFalse();

    // Attempting API update returns 403 Forbidden
    $updateResponse = $this->actingAs($this->user)
        ->putJson("/api/v1/sales/invoices/{$invoice->id}", [
            'notes' => 'Tampered invoice notes',
        ]);
    $updateResponse->assertStatus(403);

    // Attempting API delete returns 403 Forbidden
    $deleteResponse = $this->actingAs($this->user)
        ->deleteJson("/api/v1/sales/invoices/{$invoice->id}");
    $deleteResponse->assertStatus(403);
});

test('CRIT-06: bootstrap support endpoint is protected in production environment', function (): void {
    // In local / testing, endpoint is accessible
    $response = $this->getJson('/api/v1/system/bootstrap-support');
    $response->assertSuccessful();

    // Simulate production environment
    app()->detectEnvironment(fn () => 'production');
    config(['services.bootstrap.secret' => 'super-secret-key-12345']);

    // Without secret header/query, request must be rejected with 403
    $prodResponse = $this->getJson('/api/v1/system/bootstrap-support');
    $prodResponse->assertStatus(403);

    // With wrong secret, rejected with 403
    $wrongSecretResponse = $this->withHeaders(['X-Bootstrap-Secret' => 'wrong-secret'])
        ->getJson('/api/v1/system/bootstrap-support');
    $wrongSecretResponse->assertStatus(403);

    // With correct secret header, request is allowed
    $correctSecretResponse = $this->withHeaders(['X-Bootstrap-Secret' => 'super-secret-key-12345'])
        ->getJson('/api/v1/system/bootstrap-support');
    $correctSecretResponse->assertSuccessful();

    // Revert environment to testing
    app()->detectEnvironment(fn () => 'testing');
});

test('HIGH-05: conversion actions use pessimistic locking and prevent double conversions', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Construction SRL',
        'type' => 'company',
        'vat_number' => 'IT11223344556',
        'email' => 'client@build.com',
    ]);

    $quote = Quote::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'quote_number' => 'QT-2026-9001',
        'date' => '2026-10-15',
        'status' => 'draft',
        'currency' => 'EUR',
        'total_net' => 500.0,
        'total_tax' => 110.0,
        'total_gross' => 610.0,
    ]);

    $quote->items()->create([
        'description' => 'Electrical wiring work',
        'quantity' => 1,
        'unit_price' => 500.0,
        'tax_rate' => 22.0,
        'line_total' => 500.0,
    ]);

    // 1. Convert quote to order
    $orderAction = app(ConvertQuoteToOrder::class);
    $order = $orderAction->execute($quote);
    expect($order)->toBeInstanceOf(SalesOrder::class)
        ->and($order->quote_id)->toBe($quote->id);

    // Attempting to convert the same quote to order again throws ValidationException
    expect(fn () => $orderAction->execute($quote))->toThrow(ValidationException::class);

    // 2. Convert order to invoice
    $invoiceAction = app(ConvertToInvoice::class);
    $invoice = $invoiceAction->fromOrder($order);
    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->sales_order_id)->toBe($order->id);

    // Attempting to invoice the same order again throws ValidationException
    expect(fn () => $invoiceAction->fromOrder($order))->toThrow(ValidationException::class);
});

test('HIGH-06: queue jobs are configured with afterCommit = true', function (): void {
    $emailJob = new SendDocumentEmailJob('test-uuid');
    expect($emailJob->afterCommit)->toBeTrue();

    $stmtJob = new SendAccountStatementEmail('test-uuid', '2026-10-28');
    expect($stmtJob->afterCommit)->toBeTrue();

    $remJob = new SendPaymentReminderEmail('test-uuid');
    expect($remJob->afterCommit)->toBeTrue();

    $subJob = new SendSubscriptionRenewalNotice('test-uuid');
    expect($subJob->afterCommit)->toBeTrue();
});

test('HIGH-07: document endpoints enforce policy authorization against cross-tenant access', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Document Client',
        'type' => 'company',
        'vat_number' => 'IT11223344556',
        'email' => 'client@doc.com',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2026-7777',
        'date' => '2026-10-10',
        'total_net' => 100.0,
        'total_tax' => 22.0,
        'total_gross' => 122.0,
        'payment_status' => 'unpaid',
    ]);

    // Authorized tenant user can access PDF and SDI logs
    $this->actingAs($this->user)
        ->getJson("/api/v1/documents/invoice/{$invoice->id}/pdf")
        ->assertSuccessful();

    $this->actingAs($this->user)
        ->getJson("/api/v1/sales/invoices/{$invoice->id}/sdi-logs")
        ->assertSuccessful();

    // Cross-tenant user is forbidden (403 or 404 depending on tenant scope)
    $foreignPdf = $this->actingAs($this->otherUser)
        ->getJson("/api/v1/documents/invoice/{$invoice->id}/pdf");
    expect(in_array($foreignPdf->status(), [403, 404], true))->toBeTrue();

    $foreignSdiLogs = $this->actingAs($this->otherUser)
        ->getJson("/api/v1/sales/invoices/{$invoice->id}/sdi-logs");
    expect(in_array($foreignSdiLogs->status(), [403, 404], true))->toBeTrue();

    $foreignEmail = $this->actingAs($this->otherUser)
        ->postJson("/api/v1/documents/invoice/{$invoice->id}/email", [
            'to' => 'attacker@foreign.com',
        ]);
    expect(in_array($foreignEmail->status(), [403, 404], true))->toBeTrue();
});

test('HIGH-08: soft-deleted fiscal documents block contact deletion to preserve statutory retention', function (): void {
    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Audited Client SRL',
        'type' => 'company',
        'vat_number' => 'IT11223344556',
        'email' => 'audit@client.com',
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2026-8888',
        'date' => '2026-10-01',
        'payment_status' => 'unpaid',
        'total_net' => 50.0,
        'total_tax' => 11.0,
        'total_gross' => 61.0,
    ]);

    // Active invoice blocks contact deletion
    expect(Gate::forUser($this->user)->allows('delete', $contact))->toBeFalse();

    // Soft delete the invoice
    $invoice->delete();
    expect($invoice->trashed())->toBeTrue();

    // Even though the invoice is soft-deleted, ContactPolicy::delete must still return false
    expect(Gate::forUser($this->user)->allows('delete', $contact))->toBeFalse();

    // Attempting API contact deletion returns 403 Forbidden
    $deleteResponse = $this->actingAs($this->user)
        ->deleteJson("/api/v1/crm/contacts/{$contact->id}");
    $deleteResponse->assertStatus(403);
});
