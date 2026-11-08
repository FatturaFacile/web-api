<?php

declare(strict_types=1);

namespace Modules\Payroll\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

/**
 * Stores the payroll events in the outbox, in the transaction that produced them. The outbox service deduplicates the
 * event object, so an event published through EventDispatcher::publishAndDispatch() is stored once.
 */
final readonly class DispatchOutboxOnPayrollEventListener
{
    public function __construct(private TransactionalOutboxService $outboxService) {}

    /**
     * Record the event in the transactional outbox.
     */
    public function handle(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
    }
}
