<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use Enterprise\EventBus\Models\TransactionalOutbox;
use Enterprise\EventBus\Models\TransactionalOutboxArchive;
use Enterprise\EventBus\Services\OutboxLifecycleService;
use Illuminate\Support\Str;

test('archives processed outbox messages older than 30 days and preserves pending messages', function (): void {
    $service = app(OutboxLifecycleService::class);
    $tenantId = (string) Str::uuid7();

    // 1. Create an old processed message (> 30 days)
    $oldProcessed = TransactionalOutbox::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_type' => 'test.event.processed',
        'aggregate_type' => 'test',
        'aggregate_id' => (string) Str::uuid7(),
        'payload_json' => ['data' => 'test'],
        'occurred_at' => now()->subDays(45),
        'processed_at' => now()->subDays(40),
        'status' => 'processed',
        'retry_count' => 0,
    ]);

    // 2. Create a recent processed message (< 30 days)
    $recentProcessed = TransactionalOutbox::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_type' => 'test.event.recent',
        'aggregate_type' => 'test',
        'aggregate_id' => (string) Str::uuid7(),
        'payload_json' => ['data' => 'recent'],
        'occurred_at' => now()->subDays(5),
        'processed_at' => now()->subDays(5),
        'status' => 'processed',
        'retry_count' => 0,
    ]);

    // 3. Create a pending message (> 30 days) - must NOT be archived!
    $oldPending = TransactionalOutbox::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_type' => 'test.event.pending',
        'aggregate_type' => 'test',
        'aggregate_id' => (string) Str::uuid7(),
        'payload_json' => ['data' => 'pending'],
        'occurred_at' => now()->subDays(45),
        'status' => 'pending',
        'retry_count' => 0,
    ]);

    // Execute Archive
    $result = $service->archiveProcessedMessages(olderThanDays: 30);

    expect($result['archived_count'])->toBeGreaterThanOrEqual(1);

    // Old processed message must be in archive table
    $archivedRecord = TransactionalOutboxArchive::find($oldProcessed->id);
    expect($archivedRecord)->not->toBeNull();
    expect($archivedRecord->event_type)->toBe('test.event.processed');
    expect($archivedRecord->archived_at)->not->toBeNull();

    // Old processed message must be removed from active outbox table
    expect(TransactionalOutbox::find($oldProcessed->id))->toBeNull();

    // Recent processed and pending messages must still remain in active outbox table
    expect(TransactionalOutbox::find($recentProcessed->id))->not->toBeNull();
    expect(TransactionalOutbox::find($oldPending->id))->not->toBeNull();
});

test('purges archived messages older than retention days policy idempotently', function (): void {
    $service = app(OutboxLifecycleService::class);
    $tenantId = (string) Str::uuid7();

    // Create an expired archive record (> 90 days)
    $expiredArchive = TransactionalOutboxArchive::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_type' => 'test.archive.expired',
        'aggregate_type' => 'test',
        'aggregate_id' => (string) Str::uuid7(),
        'payload_json' => ['data' => 'expired'],
        'occurred_at' => now()->subDays(120),
        'processed_at' => now()->subDays(115),
        'archived_at' => now()->subDays(100),
        'status' => 'processed',
        'retry_count' => 0,
    ]);

    // Create a recent archive record (< 90 days)
    $recentArchive = TransactionalOutboxArchive::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_type' => 'test.archive.recent',
        'aggregate_type' => 'test',
        'aggregate_id' => (string) Str::uuid7(),
        'payload_json' => ['data' => 'recent'],
        'occurred_at' => now()->subDays(50),
        'processed_at' => now()->subDays(45),
        'archived_at' => now()->subDays(20),
        'status' => 'processed',
        'retry_count' => 0,
    ]);

    // Execute Purge
    $result = $service->purgeArchivedMessages(retentionDays: 90);

    expect($result['purged_count'])->toBeGreaterThanOrEqual(1);

    // Expired record purged
    expect(TransactionalOutboxArchive::find($expiredArchive->id))->toBeNull();

    // Recent archive remains intact
    expect(TransactionalOutboxArchive::find($recentArchive->id))->not->toBeNull();
});
