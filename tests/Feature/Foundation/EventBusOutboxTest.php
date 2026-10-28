<?php

declare(strict_types=1);

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Jobs\RelayOutboxEventsJob;
use Enterprise\EventBus\Models\TransactionalInbox;
use Enterprise\EventBus\Services\TransactionalInboxService;
use Enterprise\EventBus\Services\TransactionalOutboxService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(DatabaseTransactions::class);

test('event bus records domain events in outbox table and relays them to event dispatcher', function () {
    Event::fake(['invoice.created']);

    $outboxService = app(TransactionalOutboxService::class);
    $tenantId = (string) Str::uuid7();
    $companyId = (string) Str::uuid7();
    $invoiceId = (string) Str::uuid7();

    $event = new class($tenantId, $companyId, $invoiceId) implements DomainEventInterface
    {
        public function __construct(
            private readonly string $tenantId,
            private readonly string $companyId,
            private readonly string $invoiceId
        ) {}

        public function getEventName(): string
        {
            return 'invoice.created';
        }

        public function getAggregateType(): string
        {
            return 'Invoice';
        }

        public function getAggregateId(): string
        {
            return $this->invoiceId;
        }

        public function getTenantId(): string
        {
            return $this->tenantId;
        }

        public function getCompanyId(): string
        {
            return $this->companyId;
        }

        public function toPayload(): array
        {
            return [
                'invoice_id' => $this->invoiceId,
                'amount' => 1250.00,
            ];
        }
    };

    $outbox = $outboxService->record($event);

    expect($outbox->id)->not->toBeEmpty()
        ->and($outbox->status)->toBe('pending');

    (new RelayOutboxEventsJob)->handle();

    $outbox->refresh();
    expect($outbox->status)->toBe('completed')
        ->and($outbox->processed_at)->not->toBeNull();

    Event::assertDispatched('invoice.created');
});

test('transactional inbox enforces idempotency and suppresses duplicate processing', function () {
    $inboxService = app(TransactionalInboxService::class);
    $messageId = 'msg_'.(string) Str::uuid7();
    $tenantId = (string) Str::uuid7();

    $executionCount = 0;
    $handler = function () use (&$executionCount) {
        $executionCount++;

        return 'processed';
    };

    // First execution: should execute
    $result1 = $inboxService->executeIdempotent(
        messageId: $messageId,
        eventType: 'payment.received',
        tenantId: $tenantId,
        payload: ['amount' => 500],
        handler: $handler
    );

    // Second execution with identical messageId: should be bypassed idempotently
    $result2 = $inboxService->executeIdempotent(
        messageId: $messageId,
        eventType: 'payment.received',
        tenantId: $tenantId,
        payload: ['amount' => 500],
        handler: $handler
    );

    expect($executionCount)->toBe(1)
        ->and($result1)->toBe('processed')
        ->and($result2)->toBeNull()
        ->and($inboxService->hasBeenProcessed($messageId))->toBeTrue();

    $inboxEntry = TransactionalInbox::where('message_id', $messageId)->first();
    expect($inboxEntry->status)->toBe('completed');
});
