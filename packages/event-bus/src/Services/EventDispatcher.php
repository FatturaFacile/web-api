<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Services;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\Event;

final class EventDispatcher implements EventBusInterface, EventDispatcherInterface
{
    public function __construct(
        private readonly TransactionalOutboxService $outboxService
    ) {}

    public function dispatch(DomainEventInterface $event): void
    {
        // Immediate local dispatch for both string name and event class type
        Event::dispatch($event->getEventName(), [$event]);
        Event::dispatch($event);
    }

    public function publish(DomainEventInterface $event): void
    {
        // Reliable asynchronous outbox recording
        $this->outboxService->record($event);
    }

    /**
     * The outbox relay re-dispatches only the string event name with an array payload,
     * which no class-based listener receives; this helper therefore also dispatches the
     * event object in-process (the InvoicingService pattern).
     *
     * Synchronous listeners (for example the General Ledger postings) run inside the
     * caller's transaction, so a failed posting rolls back the state change instead of
     * leaving it without its journal entry. Queued listeners that react to these events
     * implement ShouldQueueAfterCommit, so their jobs are pushed only once the transaction
     * commits. Outbox listeners receiving the same object are deduplicated by
     * TransactionalOutboxService::record().
     */
    public function publishAndDispatch(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
        $this->dispatch($event);
    }
}
