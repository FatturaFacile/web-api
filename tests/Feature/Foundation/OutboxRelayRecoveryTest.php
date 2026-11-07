<?php

declare(strict_types=1);

use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Enterprise\EventBus\Models\TransactionalOutboxArchive;
use Enterprise\EventBus\Services\OutboxLifecycleService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    TransactionalOutbox::query()->delete();
});

function relayRecoveryOutbox(array $overrides = []): TransactionalOutbox
{
    $tenantId = (string) Str::uuid7();

    return TransactionalOutbox::create(array_merge([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'company_id' => $tenantId,
        'event_type' => 'relay.recovery.test',
        'aggregate_type' => 'test',
        'aggregate_id' => (string) Str::uuid7(),
        'payload_json' => ['data' => 'recovery'],
        'occurred_at' => now(),
        'status' => 'pending',
        'retry_count' => 0,
    ], $overrides));
}

test('events relayed as completed are archived by the lifecycle service', function () {
    Event::fake(['relay.recovery.test']);
    $outbox = relayRecoveryOutbox();

    (new RelayOutboxEventsJob)->handle();
    expect($outbox->refresh()->status)->toBe('completed');

    $this->travel(31)->days();
    $result = app(OutboxLifecycleService::class)->archiveProcessedMessages(30);

    expect($result['archived_count'])->toBe(1)
        ->and(TransactionalOutbox::find($outbox->id))->toBeNull()
        ->and(TransactionalOutboxArchive::find($outbox->id)?->status)->toBe('completed');
});

test('legacy processed rows are still archived and counted', function () {
    $legacy = relayRecoveryOutbox(['status' => 'processed', 'processed_at' => now()->subDays(40), 'occurred_at' => now()->subDays(40)]);
    relayRecoveryOutbox(['status' => 'completed', 'processed_at' => now()->subDay()]);

    $service = app(OutboxLifecycleService::class);
    expect($service->getMetrics()['processed_count'])->toBe(2);

    $service->archiveProcessedMessages(30);

    expect(TransactionalOutbox::find($legacy->id))->toBeNull()
        ->and($service->getMetrics()['processed_count'])->toBe(1);
});

test('an event left processing by a dead worker is relayed again once its lease expires', function () {
    Event::fake(['relay.recovery.test']);
    $stuck = relayRecoveryOutbox(['status' => 'processing', 'retry_after' => now()->subMinute(), 'occurred_at' => now()->subHour()]);

    (new RelayOutboxEventsJob)->handle();

    $stuck->refresh();
    expect($stuck->status)->toBe('completed')
        ->and($stuck->retry_count)->toBe(1);
    Event::assertDispatched('relay.recovery.test');
});

test('an event stuck in processing from before leases existed is reclaimed when old enough', function () {
    Event::fake(['relay.recovery.test']);
    $stuck = relayRecoveryOutbox(['status' => 'processing', 'retry_after' => null, 'occurred_at' => now()->subMinutes(30)]);

    (new RelayOutboxEventsJob(staleProcessingMinutes: 10))->handle();

    expect($stuck->refresh()->status)->toBe('completed');
});

test('an event processing within its lease is left to its worker', function () {
    Event::fake(['relay.recovery.test']);
    $busy = relayRecoveryOutbox(['status' => 'processing', 'retry_after' => now()->addMinutes(5)]);

    (new RelayOutboxEventsJob)->handle();

    expect($busy->refresh()->status)->toBe('processing');
    Event::assertNotDispatched('relay.recovery.test');
});

test('a stuck event without retries left is dead-lettered instead of relayed', function () {
    Event::fake(['relay.recovery.test']);
    $poison = relayRecoveryOutbox([
        'status' => 'processing',
        'retry_after' => now()->subMinute(),
        'retry_count' => RelayOutboxEventsJob::MAX_RETRIES - 1,
    ]);

    (new RelayOutboxEventsJob)->handle();

    expect($poison->refresh()->status)->toBe('failed')
        ->and($poison->retry_count)->toBe(RelayOutboxEventsJob::MAX_RETRIES);
    Event::assertNotDispatched('relay.recovery.test');
});

test('a claimed event carries a lease while it is processing', function () {
    $outbox = relayRecoveryOutbox();
    $this->freezeTime();

    Event::listen('relay.recovery.test', function () use ($outbox): void {
        $row = TransactionalOutbox::find($outbox->id);

        expect($row->status)->toBe('processing')
            ->and($row->retry_after->timestamp)->toBe(now()->addMinutes(10)->timestamp);
    });

    (new RelayOutboxEventsJob)->handle();

    expect($outbox->refresh()->status)->toBe('completed')
        ->and($outbox->retry_after)->toBeNull();
});
