<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

final readonly class DispatchOutboxOnESMEventListener
{
    public function __construct(private TransactionalOutboxService $outbox) {}

    public function handle(DomainEventInterface $event): void
    {
        // The service falls back to the active tenant when an event carries an empty or non-uuid tenant / company id.
        $this->outbox->record($event);
    }
}
