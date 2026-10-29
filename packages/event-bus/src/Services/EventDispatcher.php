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
}
