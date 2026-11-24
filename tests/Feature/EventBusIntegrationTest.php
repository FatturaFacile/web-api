<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\EventBus\Models\TransactionalInbox;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Enterprise\EventBus\Services\TransactionalInboxService;
use Enterprise\EventBus\Services\TransactionalOutboxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    TransactionalOutbox::query()->delete();
    TransactionalInbox::query()->delete();

    $this->tenant = Tenant::firstOrCreate(
        ['name' => 'tenant_eventbus_test'],
        [
            'legal_name' => 'Event Bus Verification Corp',
            'fiscal_code' => 'IT99887711223',
            'vat_number' => 'IT99887711223',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]
    );
});

test('Event Bus: captures domain events in transactional outbox and relays to dispatcher', function () {
    Event::fake(['enterprise.order.confirmed']);

    $outboxService = app(TransactionalOutboxService::class);
    $tenantId = $this->tenant->id;
    $aggregateId = (string) Str::uuid7();

    $event = new class($tenantId, $aggregateId) implements DomainEventInterface
    {
        public function __construct(
            private readonly string $tenantId,
            private readonly string $aggregateId
        ) {}

        public function getEventName(): string
        {
            return 'enterprise.order.confirmed';
        }

        public function getAggregateType(): string
        {
            return 'SalesOrder';
        }

        public function getAggregateId(): string
        {
            return $this->aggregateId;
        }

        public function getTenantId(): string
        {
            return $this->tenantId;
        }

        public function getCompanyId(): string
        {
            return $this->tenantId;
        }

        public function toPayload(): array
        {
            return [
                'order_id' => $this->aggregateId,
                'total_amount' => 12500.00,
                'currency' => 'EUR',
                'status' => 'CONFIRMED',
            ];
        }
    };

    $outboxRecord = $outboxService->record($event);

    expect($outboxRecord)->not->toBeNull()
        ->and($outboxRecord->event_type)->toBe('enterprise.order.confirmed')
        ->and($outboxRecord->aggregate_type)->toBe('SalesOrder')
        ->and($outboxRecord->aggregate_id)->toBe($aggregateId)
        ->and($outboxRecord->status)->toBe('pending');

    // Run relay job
    (new RelayOutboxEventsJob)->handle();

    $refreshed = TransactionalOutbox::find($outboxRecord->id);
    expect($refreshed->status)->toBe('completed')
        ->and($refreshed->processed_at)->not->toBeNull();

    Event::assertDispatched('enterprise.order.confirmed');
});

test('Event Bus: Transactional Inbox guarantees idempotency and deduplication', function () {
    $inboxService = app(TransactionalInboxService::class);
    $tenantId = $this->tenant->id;
    $messageId = 'msg_eb_'.(string) Str::uuid7();

    $executionCount = 0;
    $handler = function () use (&$executionCount) {
        $executionCount++;

        return 'receipt_processed_ok';
    };

    $firstRun = $inboxService->executeIdempotent(
        messageId: $messageId,
        eventType: 'procurement.receipt.processed',
        tenantId: $tenantId,
        payload: ['receipt_id' => (string) Str::uuid7(), 'status' => 'STORED'],
        handler: $handler
    );

    $secondRun = $inboxService->executeIdempotent(
        messageId: $messageId,
        eventType: 'procurement.receipt.processed',
        tenantId: $tenantId,
        payload: ['receipt_id' => (string) Str::uuid7(), 'status' => 'STORED'],
        handler: $handler
    );

    expect($executionCount)->toBe(1)
        ->and($firstRun)->toBe('receipt_processed_ok')
        ->and($secondRun)->toBeNull()
        ->and($inboxService->hasBeenProcessed($messageId))->toBeTrue();

    $entry = TransactionalInbox::where('message_id', $messageId)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->status)->toBe('completed');
});
