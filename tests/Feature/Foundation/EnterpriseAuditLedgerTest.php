<?php

declare(strict_types=1);

use App\Models\Tenant;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

test('audit ledger records model lifecycle events with deterministic cryptographic hash chaining', function () {
    $service = app(AuditLedgerService::class);
    $verifier = app(AuditChainVerificationService::class);

    $tenantId = (string) Str::uuid7();
    $tenantModel = new Tenant([
        'name' => 'Acme Corporation',
        'legal_name' => 'Acme Corporation S.r.l.',
        'vat_number' => 'IT12345678901',
        'status' => 'active',
    ]);
    $tenantModel->id = $tenantId;

    // 1. Record CREATED
    $service->record(
        action: 'CREATED',
        model: $tenantModel,
        payloadBefore: null,
        payloadAfter: ['name' => 'Acme Corporation'],
        moduleCode: 'CORE'
    );

    // 2. Record UPDATED
    $service->record(
        action: 'UPDATED',
        model: $tenantModel,
        payloadBefore: ['name' => 'Acme Corporation'],
        payloadAfter: ['name' => 'Acme Corporation Ltd'],
        moduleCode: 'CORE'
    );

    $entries = AuditLedgerEntry::where('tenant_id', $tenantId)->orderBy('event_timestamp', 'asc')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->action)->toBe('CREATED')
        ->and($entries[1]->action)->toBe('UPDATED')
        ->and($entries[1]->previous_hash)->toBe($entries[0]->record_hash);

    // 3. Verify cryptographic chain validity
    $result = $verifier->verifyTenantChain($tenantId);
    expect($result['valid'])->toBeTrue($result['error_message'] ?? 'Chain verification failed')
        ->and($result['total_verified'])->toBe(2);
});

test('audit ledger models are strictly immutable and throw LogicException on update or delete', function () {
    $service = app(AuditLedgerService::class);
    $tenantId = (string) Str::uuid7();

    $tenantModel = new Tenant([
        'name' => 'Secure Enterprise',
        'legal_name' => 'Secure Enterprise S.r.l.',
        'vat_number' => 'IT98765432109',
        'status' => 'active',
    ]);
    $tenantModel->id = $tenantId;

    $service->record(
        action: 'CREATED',
        model: $tenantModel,
        payloadBefore: null,
        payloadAfter: ['name' => 'Secure Enterprise'],
        moduleCode: 'CORE'
    );

    $entry = AuditLedgerEntry::where('tenant_id', $tenantId)->firstOrFail();

    // Attempting update must throw LogicException
    expect(fn () => $entry->update(['action' => 'TAMPERED']))
        ->toThrow(LogicException::class, 'Audit ledger records are strictly immutable and cannot be updated.');

    // Attempting delete must throw LogicException
    expect(fn () => $entry->delete())
        ->toThrow(LogicException::class, 'Audit ledger records are strictly immutable and cannot be deleted.');
});
