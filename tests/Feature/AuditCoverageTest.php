<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use LogicException;

uses(DatabaseTransactions::class);

test('Audit Ledger: records model lifecycle events with deterministic cryptographic hash chaining', function () {
    $service = app(AuditLedgerService::class);
    $verifier = app(AuditChainVerificationService::class);

    $tenantId = (string) Str::uuid7();
    $tenantModel = new Tenant([
        'name' => 'Audit Enterprise Corp',
        'legal_name' => 'Audit Enterprise Corp S.r.l.',
        'vat_number' => 'IT88888888888',
        'status' => 'active',
    ]);
    $tenantModel->id = $tenantId;

    // 1. Record CREATED
    $service->record(
        action: 'CREATED',
        model: $tenantModel,
        payloadBefore: null,
        payloadAfter: ['name' => 'Audit Enterprise Corp'],
        moduleCode: 'CORE'
    );

    usleep(5000);

    // 2. Record UPDATED
    $service->record(
        action: 'UPDATED',
        model: $tenantModel,
        payloadBefore: ['name' => 'Audit Enterprise Corp'],
        payloadAfter: ['name' => 'Audit Enterprise Corp Updated'],
        moduleCode: 'CORE'
    );

    $entries = AuditLedgerEntry::where('tenant_id', $tenantId)
        ->orderBy('event_timestamp', 'asc')
        ->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->action)->toBe('CREATED')
        ->and($entries[1]->action)->toBe('UPDATED')
        ->and($entries[1]->previous_hash)->toBe($entries[0]->record_hash);

    $result = $verifier->verifyTenantChain($tenantId);
    expect($result['valid'])->toBeTrue($result['error_message'] ?? 'Chain verification failed')
        ->and($result['total_verified'])->toBe(2);
});

test('Audit Ledger: models are strictly immutable and throw LogicException on update or delete', function () {
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
