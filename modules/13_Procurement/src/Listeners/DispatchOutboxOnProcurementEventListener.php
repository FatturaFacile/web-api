<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutboxMessage;
use Illuminate\Support\Str;

final class DispatchOutboxOnProcurementEventListener
{
    public function handle(DomainEventInterface $event): void
    {
        TransactionalOutboxMessage::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId(),
            'company_id' => $event->getCompanyId(),
            'event_type' => $event->getEventName(),
            'event_payload' => $event->toPayload(),
            'status' => 'pending',
            'created_at' => now(),
        ]);
    }
}
