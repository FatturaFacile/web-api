<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEventType;
use App\Models\AuditLog;
use App\Models\OfflineAuditEntry;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->owner] = tfrTenant('Offline Audit Tenant');
});

/**
 * @return list<array<string, mixed>>
 */
function offlineChain(int $count, int $from = 1, string $previous = '0000000000000000000000000000000000000000000000000000000000000000'): array
{
    $entries = [];

    for ($sequence = $from; $sequence < $from + $count; $sequence++) {
        $record = hash('sha256', "record-{$sequence}");
        $entries[] = [
            'sequence_number' => $sequence,
            'previous_hash' => $previous,
            'payload_hash' => hash('sha256', "payload-{$sequence}"),
            'record_hash' => $record,
            'occurred_at' => '2027-12-13T08:00:0'.($sequence % 10).'.000000Z',
            'entity_type' => 'Invoice',
            'entity_id' => 'inv-'.$sequence,
            'action' => 'UPDATED',
            'origin' => 'offline',
        ];
        $previous = $record;
    }

    return $entries;
}

function uploadOffline(array $entries, string $device = 'dev-1', string $origin = 'desktop_offline')
{
    Sanctum::actingAs(test()->owner);

    return test()->withHeader('X-Tenant-Id', test()->tenant->id)->postJson('/api/v1/audit/offline-batches', [
        'device_id' => $device, 'origin' => $origin, 'batch_id' => 'batch-'.$device.'-'.$entries[0]['sequence_number'], 'entries' => $entries,
    ]);
}

test('a batch is acknowledged per entry and appended to the server audit chain', function (): void {
    $response = uploadOffline(offlineChain(3))->assertOk()
        ->assertJsonPath('data.entries.0.sequence_number', 1)
        ->assertJsonCount(3, 'data.entries');

    expect($response->json('data.received_at'))->not->toBeNull()
        ->and(OfflineAuditEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(3)
        ->and(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event_type', AuditEventType::OfflineSynced->value)->count())->toBe(3)
        ->and(app(VerifyAuditChainHandler::class)->handle(new VerifyAuditChainQuery((string) $this->tenant->id))->isValid)->toBeTrue();
});

test('a replayed batch is idempotent', function (): void {
    $entries = offlineChain(2);
    uploadOffline($entries)->assertOk();
    uploadOffline($entries)->assertOk()->assertJsonCount(2, 'data.entries');

    expect(OfflineAuditEntry::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(2)
        ->and(AuditLog::query()->where('tenant_id', $this->tenant->id)->where('event_type', AuditEventType::OfflineSynced->value)->count())->toBe(2);
});

test('a later batch continues the chain, a gap or a broken link is rejected', function (): void {
    $chain = offlineChain(4);
    uploadOffline(array_slice($chain, 0, 2))->assertOk();

    uploadOffline(array_slice($chain, 3, 1))->assertUnprocessable();

    $broken = array_slice($chain, 2, 1);
    $broken[0]['previous_hash'] = hash('sha256', 'forged');
    uploadOffline($broken)->assertUnprocessable();

    uploadOffline(array_slice($chain, 2, 2))->assertOk();
});

test('a known sequence with another hash is a conflict', function (): void {
    $entries = offlineChain(1);
    uploadOffline($entries)->assertOk();

    $entries[0]['record_hash'] = hash('sha256', 'tampered');
    uploadOffline($entries)->assertUnprocessable();
});

test('the mobile wire form with payload_json and metadata_json is accepted', function (): void {
    $entries = offlineChain(1);
    $entries[0]['payload_json'] = '{"a":1}';
    $entries[0]['metadata_json'] = '{"device":"m1"}';
    $entries[0]['uuid'] = 'c0ffee';

    uploadOffline($entries, 'mobile-1', 'mobile_offline')->assertOk();

    expect(OfflineAuditEntry::withoutGlobalScopes()->where('device_id', 'mobile-1')->value('payload'))->toBe('{"a":1}');
});

test('malformed hashes are rejected and devices of two tenants do not collide', function (): void {
    $bad = offlineChain(1);
    $bad[0]['record_hash'] = 'not-a-hash';
    uploadOffline($bad)->assertUnprocessable();

    uploadOffline(offlineChain(1))->assertOk();

    ['tenant' => $other, 'user' => $otherOwner] = tfrTenant('Other Offline Tenant');
    Sanctum::actingAs($otherOwner);
    $this->withHeader('X-Tenant-Id', $other->id)->postJson('/api/v1/audit/offline-batches', [
        'device_id' => 'dev-1', 'origin' => 'desktop_offline', 'entries' => offlineChain(1),
    ])->assertOk();

    expect(OfflineAuditEntry::withoutGlobalScopes()->where('device_id', 'dev-1')->count())->toBe(2);
});
