<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;

final readonly class DispatchOutboxOnManufacturingEventListener
{
    public function handle(DomainEventInterface $event): void
    {
        $aggregateId = $event->getAggregateId();
        if (! Str::isUuid($aggregateId)) {
            $aggregateId = (string) Str::uuid7();
        }

        TransactionalOutbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId(),
            'company_id' => $event->getCompanyId(),
            'event_type' => $event->getEventName(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $aggregateId,
            'payload_json' => $event->toPayload(),
            'status' => 'pending',
            'retry_count' => 0,
            'occurred_at' => now(),
        ]);
    }
}
