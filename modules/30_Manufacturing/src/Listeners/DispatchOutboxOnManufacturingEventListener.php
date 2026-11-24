<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

/**
 * Records manufacturing events in the transactional outbox. Runs synchronously, so the outbox row is written in the
 * same transaction as the state change; events already recorded by EventDispatcher::publishAndDispatch() are
 * deduplicated by the outbox service.
 */
final readonly class DispatchOutboxOnManufacturingEventListener
{
    public function __construct(private TransactionalOutboxService $outboxService) {}

    /**
     * Records the event once.
     */
    public function handle(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
    }
}
