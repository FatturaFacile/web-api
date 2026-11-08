<?php

declare(strict_types=1);

namespace Modules\Contracts\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

/**
 * Records the domain events of the Contracts module in the transactional outbox, from where they reach subscribers.
 */
final class DispatchOutboxOnContractEventListener
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly TransactionalOutboxService $outbox) {}

    /**
     * Handle the event.
     */
    public function handle(DomainEventInterface $event): void
    {
        $this->outbox->record($event);
    }
}
