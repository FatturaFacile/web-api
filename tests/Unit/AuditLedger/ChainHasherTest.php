<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Domain\AuditChain;
use Enterprise\AuditLedger\Domain\Services\AuditKeyring;
use Enterprise\AuditLedger\Domain\Services\ChainHasher;
use Illuminate\Support\Carbon;
use Tests\Support\AuditLedgerFixtures;

test('the keyed hash is deterministic and chains to the previous hash', function (): void {
    $hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $tenant = '0194f7a0-1111-7000-8000-000000000001';
    $at = Carbon::parse('2027-01-01 10:00:00.250000', 'UTC');

    $first = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);
    $again = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);

    expect($first['record_hash'])->toBe($again['record_hash'])
        ->and($first['sequence'])->toBe(1)
        ->and($first['previous_hash'])->toBe($hasher->genesisForTenant($tenant))
        ->and($first['record_hash'])->toMatch('/^[0-9a-f]{64}$/');

    $chain = AuditChain::resume($tenant, $hasher);
    $one = $chain->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);
    $two = $chain->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);

    expect($two['sequence'])->toBe(2)
        ->and($two['previous_hash'])->toBe($one['record_hash'])
        ->and($two['record_hash'])->not->toBe($one['record_hash']);
});

test('time never goes backwards inside a chain', function (): void {
    $hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $tenant = '0194f7a0-1111-7000-8000-000000000002';
    $at = Carbon::parse('2027-01-01 10:00:00.000000', 'UTC');

    $chain = AuditChain::resume($tenant, $hasher);
    $one = $chain->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);
    $two = $chain->append(AuditLedgerFixtures::entryFields($tenant), 1, $at->copy()->subSecond());

    expect($two['event_timestamp']->greaterThan($one['event_timestamp']))->toBeTrue();
});

test('any change of a hashed field changes the hash', function (string $field, mixed $value): void {
    $hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $tenant = '0194f7a0-1111-7000-8000-000000000003';
    $at = Carbon::parse('2027-01-01 10:00:00', 'UTC');

    $base = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);
    $changed = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant, [$field => $value]), 1, $at);

    expect($changed['record_hash'])->not->toBe($base['record_hash']);
})->with([
    ['action', 'DELETED'],
    ['module_code', 'HR'],
    ['resource_id', '0194f7a0-0000-7000-8000-000000000002'],
    ['actor_ref', '~pk:'.'b'.str_repeat('a', 31)],
    ['impersonator_ref', '~pk:'.str_repeat('c', 32)],
    ['changes', ['old' => ['name' => 'A'], 'new' => ['name' => 'C']]],
    ['context', ['channel' => 'api', 'request_id' => null]],
    ['category', 'security'],
]);

test('the hash does not depend on the order of the keys of the changes', function (): void {
    $hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $tenant = '0194f7a0-1111-7000-8000-000000000004';
    $at = Carbon::parse('2027-01-01 10:00:00', 'UTC');

    $a = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant, ['changes' => ['new' => ['b' => 1, 'a' => 2], 'old' => []]]), 1, $at);
    $b = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant, ['changes' => ['old' => [], 'new' => ['a' => 2, 'b' => 1]]]), 1, $at);

    expect($a['record_hash'])->toBe($b['record_hash']);
});

test('two tenants get different hashes for the same entry', function (): void {
    $hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $at = Carbon::parse('2027-01-01 10:00:00', 'UTC');
    $tenantA = '0194f7a0-1111-7000-8000-00000000000a';
    $tenantB = '0194f7a0-1111-7000-8000-00000000000b';

    $a = AuditChain::resume($tenantA, $hasher)->append(AuditLedgerFixtures::entryFields($tenantA, ['company_id' => $tenantA]), 1, $at);
    $b = AuditChain::resume($tenantB, $hasher)->append(AuditLedgerFixtures::entryFields($tenantA, ['company_id' => $tenantA]), 1, $at);

    expect($a['record_hash'])->not->toBe($b['record_hash'])
        ->and($a['previous_hash'])->not->toBe($b['previous_hash']);
});

test('the key version selects the key and old versions keep verifying after a rotation', function (): void {
    $keyring = AuditLedgerFixtures::keyring();
    $hasher = new ChainHasher($keyring);
    $tenant = '0194f7a0-1111-7000-8000-000000000005';
    $at = Carbon::parse('2027-01-01 10:00:00', 'UTC');

    $withV1 = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant), 1, $at);
    $withV2 = AuditChain::resume($tenant, $hasher)->append(AuditLedgerFixtures::entryFields($tenant), 2, $at);

    expect($withV1['record_hash'])->not->toBe($withV2['record_hash'])
        ->and($keyring->chainKey($tenant, 1))->not->toBe($keyring->chainKey($tenant, 2))
        // The stored version is part of the entry: the verifier recomputes the hash with that version's key.
        ->and($hasher->expectedHash($withV1, $withV1['previous_hash']))->toBe($withV1['record_hash'])
        ->and($hasher->expectedHash($withV2, $withV2['previous_hash']))->toBe($withV2['record_hash']);
});

test('the keyring derives a different key for every tenant and purpose and never returns the master key', function (): void {
    $keyring = AuditLedgerFixtures::keyring();
    $a = '0194f7a0-1111-7000-8000-00000000000a';
    $b = '0194f7a0-1111-7000-8000-00000000000b';

    expect($keyring->chainKey($a, 1))->not->toBe($keyring->chainKey($b, 1))
        ->and($keyring->chainKey($a, 1))->not->toBe($keyring->pseudonymKey($a, 1))
        ->and(strlen($keyring->chainKey($a, 1)))->toBe(32)
        ->and($keyring->chainKey($a, 1))->not->toBe('master-key-version-one-0123456789');
});

test('the keyring refuses to sign without a configured master key', function (): void {
    $keyring = new AuditKeyring([], 1, null, null);

    expect(fn () => $keyring->chainKey('0194f7a0-1111-7000-8000-00000000000a', 1))->toThrow(RuntimeException::class, 'master key');
});

test('the active key version is the highest of the default and the tenant activation', function (): void {
    $tenant = '0194f7a0-1111-7000-8000-00000000000a';
    $keyring = AuditLedgerFixtures::keyring(1, static fn (string $id): ?int => $id === $tenant ? 2 : null);

    expect($keyring->activeVersion($tenant))->toBe(2)
        ->and($keyring->activeVersion('0194f7a0-1111-7000-8000-00000000000b'))->toBe(1);
});

test('a legacy entry is hashed with the frozen sha-256 formula', function (): void {
    $hasher = new ChainHasher(AuditLedgerFixtures::keyring());
    $tenant = '0194f7a0-1111-7000-8000-000000000006';
    $at = Carbon::parse('2027-01-01 10:00:00.5', 'UTC');
    $row = [
        'tenant_id' => $tenant,
        'company_id' => $tenant,
        'event_timestamp' => $at,
        'module_code' => 'CORE',
        'action' => 'CREATED',
        'resource_type' => 'App\\Models\\Tenant',
        'resource_id' => '0194f7a0-0000-7000-8000-000000000009',
        'actor_user_id' => null,
        'payload_before' => null,
        'payload_after' => ['name' => 'Acme'],
        'hash_version' => 1,
    ];

    $expected = RecordAuditAction::computeRecordHash(str_repeat('0', 64), $tenant, $tenant, '2027-01-01T10:00:00Z', 'CORE', 'CREATED', 'App\\Models\\Tenant', '0194f7a0-0000-7000-8000-000000000009', null, null, ['name' => 'Acme']);

    expect($hasher->expectedHash($row, str_repeat('0', 64)))->toBe($expected)
        ->and($hasher->genesisFor($row))->toBe(str_repeat('0', 64));
});
