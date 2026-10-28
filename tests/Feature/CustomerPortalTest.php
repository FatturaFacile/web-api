<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Portal\PortalTokenService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ComprehensiveLookupMasterSeeder;
use Database\Seeders\ServiceReportLookupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo('2026-05-15 10:00:00');
    Storage::fake('local');

    $this->tenant = Tenant::create([
        'name' => 'Solar Installers Sagl',
        'legal_name' => 'Solar Installers Sagl',
        'vat_number' => 'CHE-999.888.777',
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);

    $this->user = User::create([
        'name' => 'Alessandro Volta',
        'email' => 'alessandro@solarinstallers.ch',
        'password' => bcrypt('secret123'),
        'locale' => 'en',
    ]);
    $this->user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    app(TenantContext::class)->setTenant($this->tenant);

    $this->customer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Villa Paradiso',
        'country' => 'CH',
        'email' => 'client@villaparadiso.ch',
    ]);

    $this->seed(ComprehensiveLookupMasterSeeder::class);
    $this->seed(ServiceReportLookupSeeder::class);

    $this->tokenService = app(PortalTokenService::class);

    // Create a quote for customer
    $this->quote = Quote::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->customer->id,
        'quote_number' => 'QT-2026-0042',
        'date' => '2026-05-10',
        'valid_until' => '2026-06-10',
        'currency' => 'CHF',
        'total_net' => 1500.00,
        'total_tax' => 121.50,
        'total_gross' => 1621.50,
        'status' => 'sent',
    ]);
    QuoteItem::create([
        'quote_id' => $this->quote->id,
        'description' => 'Solar panel installation 5kW',
        'quantity' => 1,
        'unit_price' => 1500.00,
        'tax_rate' => 8.1,
        'line_total' => 1500.00,
    ]);

    // Create an invoice
    $this->invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $this->customer->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-2026-0089',
        'date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'currency' => 'CHF',
        'total_net' => 800.00,
        'total_tax' => 64.80,
        'total_gross' => 864.80,
        'payment_status' => 'unpaid',
    ]);
    InvoiceItem::create([
        'invoice_id' => $this->invoice->id,
        'description' => 'Advance payment solar roof check',
        'quantity' => 1,
        'unit_price' => 800.00,
        'tax_rate' => 8.1,
        'line_total' => 800.00,
    ]);

    // Create a ticket
    $this->ticket = Ticket::create([
        'tenant_id' => $this->tenant->id,
        'client_id' => $this->customer->id,
        'subject' => 'Inverter connectivity issue',
        'description' => 'WiFi light blinking red on inverter.',
        'priority' => 'high',
        'status' => 'open',
    ]);

    // Add customer-facing and internal messages
    TicketMessage::create([
        'tenant_id' => $this->tenant->id,
        'ticket_id' => $this->ticket->id,
        'author_type' => 'technician',
        'author_name' => 'Alessandro Volta',
        'message' => 'Please restart your home router and check if it connects.',
        'is_internal' => false,
    ]);
    TicketMessage::create([
        'tenant_id' => $this->tenant->id,
        'ticket_id' => $this->ticket->id,
        'author_type' => 'technician',
        'author_name' => 'Alessandro Volta',
        'message' => 'Internal Note: Client firmware might be deprecated v1.2.',
        'is_internal' => true, // Must NOT be visible in portal
    ]);
});

test('customer accesses portal dashboard via valid magic link', function (): void {
    $tokenData = $this->tokenService->createToken($this->customer, 'dashboard');
    $plaintext = $tokenData['token'];

    $response = $this->getJson("/api/v1/portal/access/{$plaintext}")->assertOk();

    expect($response->json('data.customer.name'))->toBe('Villa Paradiso')
        ->and($response->json('data.summary.pending_quotes'))->toBe(1)
        ->and($response->json('data.summary.unpaid_invoices'))->toBe(1)
        ->and($response->json('data.summary.open_tickets'))->toBe(1);

    // Verify activity logged
    $this->assertDatabaseHas('portal_activity_logs', [
        'contact_id' => $this->customer->id,
        'action' => 'portal_login',
    ]);
});

test('expired magic link is rejected with 410 status', function (): void {
    $tokenData = $this->tokenService->createToken($this->customer, 'dashboard', null, now()->subMinutes(5));
    $plaintext = $tokenData['token'];

    $this->getJson("/api/v1/portal/access/{$plaintext}")->assertStatus(410);
});

test('customer views and approves quote via magic link', function (): void {
    $tokenData = $this->tokenService->createToken($this->customer, 'quote_approval', $this->quote, null, true);
    $plaintext = $tokenData['token'];

    // View quote
    $viewRes = $this->getJson("/api/v1/portal/quotes/{$plaintext}")->assertOk();
    expect($viewRes->json('data.quote_number'))->toBe('QT-2026-0042')
        ->and($viewRes->json('data.currency'))->toBe('CHF')
        ->and((float) $viewRes->json('data.total_gross'))->toEqual(1621.50)
        ->and($viewRes->json('data.is_accepted'))->toBeFalse();

    // Approve quote
    $approveRes = $this->postJson("/api/v1/portal/quotes/{$plaintext}/approve", [
        'signer_name' => 'Gianluca Bianchi',
        'notes' => 'Please start installation next Monday.',
    ])->assertOk();

    expect($approveRes->json('data.is_accepted'))->toBeTrue()
        ->and($approveRes->json('data.signed_by'))->toBe('Gianluca Bianchi');

    $this->quote->refresh();
    expect($this->quote->status)->toBe('accepted')
        ->and($this->quote->signed_by)->toBe('Gianluca Bianchi');

    // Single use token is now spent
    $this->postJson("/api/v1/portal/quotes/{$plaintext}/approve", ['signer_name' => 'Again'])
        ->assertStatus(410);
});

test('customer rejects quote via magic link', function (): void {
    $tokenData = $this->tokenService->createToken($this->customer, 'quote_approval', $this->quote);
    $plaintext = $tokenData['token'];

    $rejectRes = $this->postJson("/api/v1/portal/quotes/{$plaintext}/reject", [
        'reason' => 'Price is above our current budget.',
    ])->assertOk();

    expect($rejectRes->json('data.status'))->toBe('rejected');

    $this->quote->refresh();
    expect($this->quote->status)->toBe('rejected')
        ->and($this->quote->notes)->toContain('Price is above our current budget.');
});

test('customer lists invoices and downloads PDF via portal', function (): void {
    $tokenData = $this->tokenService->createToken($this->customer, 'dashboard');
    $plaintext = $tokenData['token'];

    // List invoices
    $listRes = $this->getJson("/api/v1/portal/invoices/{$plaintext}")->assertOk();
    expect($listRes->json('data'))->toHaveCount(1)
        ->and($listRes->json('data.0.invoice_number'))->toBe('INV-2026-0089')
        ->and($listRes->json('data.0.payment_status'))->toBe('unpaid');

    // Download PDF
    $pdfRes = $this->get("/api/v1/portal/invoices/{$plaintext}/{$this->invoice->id}/pdf");
    $pdfRes->assertOk();
    expect($pdfRes->headers->get('content-type'))->toBe('application/pdf');

    $this->assertDatabaseHas('portal_activity_logs', [
        'contact_id' => $this->customer->id,
        'action' => 'invoice_downloaded',
    ]);
});

test('customer views tickets with only customer-facing messages and posts reply', function (): void {
    $tokenData = $this->tokenService->createToken($this->customer, 'dashboard');
    $plaintext = $tokenData['token'];

    // List tickets
    $listRes = $this->getJson("/api/v1/portal/tickets/{$plaintext}")->assertOk();
    expect($listRes->json('data'))->toHaveCount(1)
        ->and($listRes->json('data.0.subject'))->toBe('Inverter connectivity issue');

    // Verify messages: customer-facing is visible, internal technician note is strictly hidden!
    $messages = $listRes->json('data.0.messages');
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['message'])->toBe('Please restart your home router and check if it connects.')
        ->and(collect($messages)->pluck('message'))->not->toContain('Internal Note: Client firmware might be deprecated v1.2.');

    // Customer replies
    $replyRes = $this->postJson("/api/v1/portal/tickets/{$plaintext}/reply/{$this->ticket->id}", [
        'message' => 'Restarted router, light is green now!',
        'author_name' => 'Gianluca Bianchi',
    ])->assertCreated();

    expect($replyRes->json('data.message'))->toBe('Restarted router, light is green now!');

    $this->assertDatabaseHas('ticket_messages', [
        'ticket_id' => $this->ticket->id,
        'author_type' => 'customer',
        'is_internal' => false,
        'message' => 'Restarted router, light is green now!',
    ]);
});

test('cross-customer access is completely blocked', function (): void {
    $otherCustomer = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Secret Customer SA',
        'country' => 'CH',
    ]);

    $otherInvoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $otherCustomer->id,
        'invoice_type' => 'standard',
        'invoice_number' => 'INV-SECRET-01',
        'date' => '2026-05-01',
        'due_date' => '2026-05-31',
        'currency' => 'CHF',
        'total_net' => 10000.00,
        'total_tax' => 810.00,
        'total_gross' => 10810.00,
        'payment_status' => 'paid',
    ]);

    // Token generated for customer A
    $tokenData = $this->tokenService->createToken($this->customer, 'dashboard');
    $plaintext = $tokenData['token'];

    // Attempting to download Customer B's invoice fails with 404
    $this->get("/api/v1/portal/invoices/{$plaintext}/{$otherInvoice->id}/pdf")
        ->assertNotFound();
});
