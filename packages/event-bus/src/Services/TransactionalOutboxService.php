<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Services;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;

final class TransactionalOutboxService
{
    public function record(DomainEventInterface $event): TransactionalOutbox
    {
        return TransactionalOutbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId(),
            'company_id' => $event->getCompanyId(),
            'event_type' => $event->getEventName(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $event->getAggregateId(),
            'payload_json' => $event->toPayload(),
            'occurred_at' => now(),
            'status' => 'pending',
            'retry_count' => 0,
        ]);
    }
}
