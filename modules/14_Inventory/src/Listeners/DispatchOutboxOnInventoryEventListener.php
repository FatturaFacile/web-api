<?php

declare(strict_types=1);

namespace Modules\Inventory\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

final class DispatchOutboxOnInventoryEventListener
{
    public function handle(DomainEventInterface $event): void
    {
        app(TransactionalOutboxService::class)->record($event);
    }
}
