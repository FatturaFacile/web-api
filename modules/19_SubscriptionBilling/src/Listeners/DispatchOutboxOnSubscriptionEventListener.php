<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

/**
 * Records the domain events of the module in the transactional outbox. The outbox service deduplicates the event
 * objects already recorded by EventDispatcher::publishAndDispatch, so each event is written once.
 */
final readonly class DispatchOutboxOnSubscriptionEventListener
{
    public function __construct(private TransactionalOutboxService $outbox) {}

    public function handle(DomainEventInterface $event): void
    {
        $this->outbox->record($event);
    }
}
