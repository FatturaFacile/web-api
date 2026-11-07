<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

/**
 * Runs synchronously so the outbox row is written in the same transaction as the
 * state change and deduplicated against EventDispatcher::publishAndDispatch().
 */
final readonly class DispatchOutboxOnGeneralLedgerEventListener
{
    public function __construct(
        private TransactionalOutboxService $outboxService
    ) {}

    public function handle(DomainEventInterface $event): void
    {
        $this->outboxService->record($event);
    }
}
