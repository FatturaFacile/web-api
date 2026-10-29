<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Services\TransactionalOutboxService;

final class DispatchOutboxOnProcurementEventListener
{
    public function handle(DomainEventInterface $event): void
    {
        app(TransactionalOutboxService::class)->record($event);
    }
}
