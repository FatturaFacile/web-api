<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Contracts;

interface EventBusInterface
{
    /**
     * Dispatch the event in-process immediately, without recording it in the outbox.
     */
    public function dispatch(DomainEventInterface $event): void;

    /**
     * Record the event in the transactional outbox only (for external consumers).
     */
    public function publish(DomainEventInterface $event): void;

    /**
     * Record the event in the transactional outbox and dispatch the event object
     * in-process, so class-based listeners run exactly once.
     */
    public function publishAndDispatch(DomainEventInterface $event): void;
}
