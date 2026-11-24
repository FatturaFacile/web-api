<?php

declare(strict_types=1);

namespace Modules\CRM\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;

final class DispatchOutboxOnCRMEventListener
{
    public function handle(DomainEventInterface $event): void
    {
        TransactionalOutbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId(),
            'company_id' => $event->getCompanyId(),
            'event_type' => $event->getEventName(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $event->getAggregateId(),
            'payload_json' => $event->toPayload(),
            'status' => 'pending',
            'retry_count' => 0,
            'occurred_at' => now(),
        ]);
    }
}
