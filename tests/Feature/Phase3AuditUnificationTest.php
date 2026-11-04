<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create([
        'name' => 'Audit Test Corp',
        'legal_name' => 'Audit Test Corp S.p.A.',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    $this->adminUser = User::create([
        'name' => 'Audit Admin',
        'email' => 'admin.'.Str::random(6).'@fatturafacile.eu',
        'password' => bcrypt('StrongPass123!'),
        'locale' => 'it',
    ]);
    $this->adminUser->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);

    $this->staffUser = User::create([
        'name' => 'Staff Member',
        'email' => 'staff.'.Str::random(6).'@fatturafacile.eu',
        'password' => bcrypt('StrongPass123!'),
        'locale' => 'it',
    ]);
    $this->staffUser->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    app(TenantContext::class)->setTenant($this->tenant);
});

test('settings audit-logs endpoint requires strict admin or compliance role', function (): void {
    // 1. Unauthenticated request -> 401
    $this->getJson('/api/v1/settings/audit-logs')
        ->assertUnauthorized();

    // 2. Member request -> 403 Forbidden
    $this->actingAs($this->staffUser)
        ->withHeaders(['X-Tenant' => $this->tenant->id])
        ->getJson('/api/v1/settings/audit-logs')
        ->assertForbidden();

    // 3. Admin request -> 200 OK
    $response = $this->actingAs($this->adminUser)
        ->withHeaders(['X-Tenant' => $this->tenant->id])
        ->getJson('/api/v1/settings/audit-logs');

    $response->assertOk()
        ->assertJsonStructure([
            'success',
            'data' => [
                'data',
                'current_page',
                'total',
            ],
            'message',
        ]);
});

test('critical domain events on invoice, contact, payment are recorded in audit ledger', function (): void {
    // 1. Contact Creation
    $contact = Contact::create([
        'tenant_id' => $this->tenant->id,
        'company_name' => 'Audited Client SRL',
        'type' => 'customer',
        'country' => 'IT',
    ]);

    $contactAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('resource_id', $contact->id)
        ->where('action', 'CREATED')
        ->first();

    expect($contactAudit)->not->toBeNull()
        ->and($contactAudit->module_code)->toBe('CRM');

    // 2. Invoice Creation & Cancellation
    $invoice = Invoice::create([
        'tenant_id' => $this->tenant->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-AUDIT-001',
        'date' => now()->toDateString(),
        'total_net' => 1000.00,
        'total_gross' => 1220.00,
        'status' => 'draft',
    ]);

    $invoiceCreatedAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('resource_id', $invoice->id)
        ->where('action', 'CREATED')
        ->first();

    expect($invoiceCreatedAudit)->not->toBeNull()
        ->and($invoiceCreatedAudit->module_code)->toBe('INVOICING');

    // Cancel / void invoice (soft delete)
    $invoice->delete();

    $invoiceCancelledAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('resource_id', $invoice->id)
        ->where('action', 'CANCELLED')
        ->first();

    expect($invoiceCancelledAudit)->not->toBeNull()
        ->and($invoiceCancelledAudit->module_code)->toBe('INVOICING');

    // 3. Payment Creation
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'invoice_id' => $invoice->id,
        'amount' => 1220.00,
        'paid_at' => now()->toDateString(),
        'payment_method' => 'MP05',
        'reference' => 'PAY-AUDIT-001',
    ]);

    $paymentAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('resource_id', $payment->id)
        ->where('action', 'CREATED')
        ->first();

    expect($paymentAudit)->not->toBeNull()
        ->and($paymentAudit->module_code)->toBe('TREASURY');
});

test('auth events login, logout, failed login are recorded in audit ledger', function (): void {
    // 1. Login Event
    event(new Login('sanctum', $this->adminUser, false));

    $loginAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('action', 'LOGIN_SUCCESS')
        ->where('actor_user_id', $this->adminUser->id)
        ->first();

    expect($loginAudit)->not->toBeNull()
        ->and($loginAudit->module_code)->toBe('AUTH');

    // 2. Logout Event
    event(new Logout('sanctum', $this->adminUser));

    $logoutAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('action', 'LOGOUT')
        ->where('actor_user_id', $this->adminUser->id)
        ->first();

    expect($logoutAudit)->not->toBeNull();

    // 3. Failed Login Event
    event(new Failed('sanctum', null, ['email' => $this->adminUser->email]));

    $failedAudit = AuditLedgerEntry::where('tenant_id', $this->tenant->id)
        ->where('action', 'LOGIN_FAILED')
        ->first();

    expect($failedAudit)->not->toBeNull();
});

test('jsonb key ordering normalization and pii redaction guarantee cryptographic chain integrity', function (): void {
    $payloadA = [
        'zebra' => 'stripe',
        'apple' => 'pie',
        'secret_token' => 'super_confidential_token_12345',
        'iban' => 'IT60X0542811101000000123456',
        'nested' => [
            'z' => 10,
            'a' => 1,
            'password' => 'secret_p@ssword',
        ],
    ];

    $payloadB = [
        'nested' => [
            'password' => 'secret_p@ssword',
            'a' => 1,
            'z' => 10,
        ],
        'iban' => 'IT60X0542811101000000123456',
        'apple' => 'pie',
        'secret_token' => 'super_confidential_token_12345',
        'zebra' => 'stripe',
    ];

    $sharedResourceId = (string) Str::uuid();

    // Compute hash for both differently-ordered payloads
    $hashA = RecordAuditAction::computeRecordHash(
        previousHash: str_repeat('0', 64),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        timestampIso: '2026-11-04T22:00:00Z',
        moduleCode: 'CORE',
        action: 'TEST',
        resourceType: 'App\\Models\\Invoice',
        resourceId: $sharedResourceId,
        actorUserId: $this->adminUser->id,
        payloadBefore: null,
        payloadAfter: $payloadA
    );

    $hashB = RecordAuditAction::computeRecordHash(
        previousHash: str_repeat('0', 64),
        tenantId: $this->tenant->id,
        companyId: $this->tenant->id,
        timestampIso: '2026-11-04T22:00:00Z',
        moduleCode: 'CORE',
        action: 'TEST',
        resourceType: 'App\\Models\\Invoice',
        resourceId: $sharedResourceId,
        actorUserId: $this->adminUser->id,
        payloadBefore: null,
        payloadAfter: $payloadB
    );

    // Hashes MUST be identical despite key order differences in JSON
    expect($hashA)->toBe($hashB);

    // Verify PII redaction
    $normalized = RecordAuditAction::normalizePayload($payloadA);
    expect($normalized['secret_token'])->toBe('[REDACTED]')
        ->and($normalized['iban'])->toBe('[REDACTED]')
        ->and($normalized['nested']['password'])->toBe('[REDACTED]');

    // Verify chain verification service
    $verificationService = app(AuditChainVerificationService::class);
    $result = $verificationService->verifyTenantChain($this->tenant->id);

    expect($result['valid'])->toBeTrue()
        ->and($result['tampered_record_id'])->toBeNull();
});
