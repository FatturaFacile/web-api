<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Actions\RecordAuditAction;
use Enterprise\AuditLedger\Models\AuditLedgerArchiveEntry;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuditLedgerFixtures;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = AuditLedgerFixtures::tenant('Archive Tenant');
    $this->tenantId = (string) $this->tenant->id;
    $this->verifier = app(AuditChainVerificationService::class);
});

/**
 * What a restore does: the entries of the backup are copied to the archive, unchanged, grouped by restore.
 */
function archiveLedgerOf(string $tenantId, string $restoreId, string $archivedBy): void
{
    foreach (DB::table('enterprise_audit_ledger')->where('tenant_id', $tenantId)->orderBy('sequence')->get() as $row) {
        DB::table('enterprise_audit_ledger_archive')->insert(array_merge((array) $row, [
            'archive_id' => (string) Str::uuid7(),
            'restore_id' => $restoreId,
            'archived_by' => $archivedBy,
            'archived_at' => now()->format('Y-m-d H:i:s.uP'),
        ]));
    }
}

/**
 * Writes one entry the way the ledger did before the forensic extension: SHA-256, no sequence of its own, payload columns.
 *
 * @param  array<string, mixed>|null  $after
 */
function legacyLedgerEntry(string $tenantId, int $sequence, string $previousHash, string $action, ?array $after, Carbon $at): string
{
    $resource = (string) Str::uuid7();
    $hash = RecordAuditAction::computeRecordHash($previousHash, $tenantId, $tenantId, $at->copy()->setTimezone('UTC')->format('Y-m-d\TH:i:s\Z'), 'CORE', $action, 'App\\Models\\Contact', $resource, null, null, $after);

    DB::table('enterprise_audit_ledger')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_timestamp' => $at->format('Y-m-d H:i:s.uP'),
        'module_code' => 'CORE',
        'action' => $action,
        'resource_type' => 'App\\Models\\Contact',
        'resource_id' => $resource,
        'actor_type' => 'system_job',
        'payload_after' => $after === null ? null : json_encode($after),
        'record_hash' => $hash,
        'previous_hash' => $previousHash,
        'sequence' => $sequence,
        'hash_version' => 1,
    ]);

    return $hash;
}

test('a chain written before the forensic extension keeps verifying and the new entries continue it', function (): void {
    $tenant = (string) Str::uuid7();
    $first = legacyLedgerEntry($tenant, 1, str_repeat('0', 64), 'CREATED', ['name' => 'Legacy'], Carbon::parse('2027-01-01 10:00:00', 'UTC'));
    $second = legacyLedgerEntry($tenant, 2, $first, 'UPDATED', ['name' => 'Legacy 2'], Carbon::parse('2027-01-01 10:00:05', 'UTC'));

    expect($this->verifier->verifyTenantChain($tenant))->toMatchArray(['valid' => true, 'total_verified' => 2]);

    $next = AuditLedgerFixtures::append($tenant, 'UPDATED', ['name' => 'Legacy 2'], ['name' => 'Modern']);

    expect($next->sequence)->toBe(3)
        ->and($next->hash_version)->toBe(2)
        ->and($next->previous_hash)->toBe($second)
        ->and($this->verifier->verifyTenantChain($tenant))->toMatchArray(['valid' => true, 'total_verified' => 3]);
});

test('an alteration of a legacy entry is still detected', function (): void {
    $tenant = (string) Str::uuid7();
    $first = legacyLedgerEntry($tenant, 1, str_repeat('0', 64), 'CREATED', ['name' => 'Legacy'], Carbon::parse('2027-01-01 10:00:00', 'UTC'));
    legacyLedgerEntry($tenant, 2, $first, 'UPDATED', ['name' => 'Legacy 2'], Carbon::parse('2027-01-01 10:00:05', 'UTC'));
    AuditLedgerFixtures::tamper($tenant, 1, ['action' => 'FORGED']);

    expect($this->verifier->verifyTenantChain($tenant))->toMatchArray(['valid' => false, 'outcome' => 'broken_at', 'broken_at_sequence' => 1]);
});

test('the readers of the old payload columns see the values of the new entries', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId, 'UPDATED', ['name' => 'Before', 'city' => 'Lugano'], ['name' => 'After', 'city' => 'Lugano']);

    expect($entry->payload_after)->toBe(['name' => 'After'])
        ->and($entry->payload_before)->toBe(['name' => 'Before'])
        ->and($entry->new_values)->toBe(['name' => 'After'])
        ->and($entry->old_values)->toBe(['name' => 'Before']);

    $legacyTenant = (string) Str::uuid7();
    legacyLedgerEntry($legacyTenant, 1, str_repeat('0', 64), 'CREATED', ['name' => 'Legacy'], Carbon::parse('2027-01-01 10:00:00', 'UTC'));

    expect(AuditLedgerFixtures::entries($legacyTenant)->first()->payload_after)->toBe(['name' => 'Legacy']);
});

test('the sequences of the entries written before the migration were backfilled in chain order', function (): void {
    $migration = require database_path('migrations/2028_01_09_000100_extend_enterprise_audit_ledger_for_forensic_trail.php');
    $tenant = (string) Str::uuid7();
    $hash = str_repeat('0', 64);

    foreach ([0, 5, 10] as $index => $offset) {
        $hash = legacyLedgerEntry($tenant, 101 + $index, $hash, 'UPDATED', ['n' => $offset], Carbon::parse('2027-02-01 10:00:00', 'UTC')->addSeconds($offset));
    }

    DB::table('enterprise_audit_ledger')->where('tenant_id', $tenant)->update(['sequence' => null]);

    $method = new ReflectionMethod($migration, 'backfillSequences');
    $method->invoke($migration, 'enterprise_audit_ledger');

    expect(DB::table('enterprise_audit_ledger')->where('tenant_id', $tenant)->orderBy('event_timestamp')->pluck('sequence')->map(fn ($s) => (int) $s)->all())->toBe([1, 2, 3])
        ->and($this->verifier->verifyTenantChain($tenant)['valid'])->toBeTrue();
});

test('the restored entries of a backup go to the archive where their chain is listed and verified', function (): void {
    $entries = AuditLedgerFixtures::chain($this->tenantId, 4);
    $restoreId = (string) Str::uuid7();

    $activeBefore = AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->count();
    archiveLedgerOf($this->tenantId, $restoreId, (string) $this->owner->id);

    $client = AuditLedgerFixtures::actingAs($this->owner, $this->tenant);

    $client->getJson('/api/v1/settings/audit-logs/archive')->assertOk()
        ->assertJsonPath('data.0.restore_id', $restoreId)
        ->assertJsonPath('data.0.entries', AuditLedgerFixtures::entries($this->tenantId)->count());

    $response = $client->getJson('/api/v1/settings/audit-logs/archive?restore_id='.$restoreId.'&verify=1&limit=500')->assertOk();

    expect(array_column($response->json('data'), 'sequence'))->toBe(AuditLedgerFixtures::entries($this->tenantId)->pluck('sequence')->all())
        ->and($response->json('meta.verification.outcome'))->toBe('valid')
        ->and($response->json('meta.restore_id'))->toBe($restoreId)
        ->and($this->verifier->verifyArchivedChain($this->tenantId, $restoreId)['valid'])->toBeTrue();

    // The active ledger is never replaced by a restore.
    expect(AuditLedgerEntry::query()->where('tenant_id', $this->tenantId)->count())->toBe($activeBefore);
});

test('an archived entry that was altered breaks the chain of its restore at its sequence', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 3);
    $restoreId = (string) Str::uuid7();

    archiveLedgerOf($this->tenantId, $restoreId, (string) $this->owner->id);

    DB::table('enterprise_audit_ledger_archive')->where('restore_id', $restoreId)->where('sequence', 2)->update(['action' => 'FORGED']);

    $result = $this->verifier->verifyArchivedChain($this->tenantId, $restoreId);

    expect($result)->toMatchArray(['valid' => false, 'outcome' => 'broken_at', 'broken_at_sequence' => 2]);

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)
        ->getJson('/api/v1/settings/audit-logs/archive?restore_id='.$restoreId.'&verify=1')->assertOk()
        ->assertJsonPath('meta.verification.outcome', 'broken_at')->assertJsonPath('meta.verification.broken_at_sequence', 2);
});

test('the archive of one tenant is never listed to another', function (): void {
    AuditLedgerFixtures::chain($this->tenantId, 2);
    $restoreId = (string) Str::uuid7();

    archiveLedgerOf($this->tenantId, $restoreId, (string) $this->owner->id);

    ['tenant' => $other, 'user' => $otherOwner] = AuditLedgerFixtures::tenant('Foreign Archive Tenant');
    $client = AuditLedgerFixtures::actingAs($otherOwner, $other);

    $client->getJson('/api/v1/settings/audit-logs/archive')->assertOk()->assertJsonPath('data', []);
    $client->getJson('/api/v1/settings/audit-logs/archive?restore_id='.$restoreId)->assertOk()->assertJsonPath('data', []);
});

test('the archive is read only for members without an auditor role and a restore id must be a uuid', function (): void {
    AuditLedgerFixtures::actingAs(AuditLedgerFixtures::member($this->tenant, 'member'), $this->tenant)
        ->getJson('/api/v1/settings/audit-logs/archive')->assertForbidden();

    AuditLedgerFixtures::actingAs($this->owner, $this->tenant)->getJson('/api/v1/settings/audit-logs/archive?restore_id=nope')->assertUnprocessable();
});

test('an archived entry cannot be updated or deleted', function (): void {
    $entry = AuditLedgerFixtures::append($this->tenantId);
    archiveLedgerOf($this->tenantId, (string) Str::uuid7(), (string) $this->owner->id);
    $archived = AuditLedgerArchiveEntry::query()->where('tenant_id', $this->tenantId)->firstOrFail();

    expect(fn () => $archived->update(['action' => 'X']))->toThrow(LogicException::class, 'cannot be updated')
        ->and(fn () => $archived->delete())->toThrow(LogicException::class, 'cannot be deleted');
});
