<?php

declare(strict_types=1);

namespace Modules\HumanResources\Listeners;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;

final readonly class DispatchOutboxOnHREventListener
{
    public function handle(DomainEventInterface $event): void
    {
        $exists = TransactionalOutbox::where('event_id', $event->getEventId())->exists();
        if ($exists) {
            return;
        }

        TransactionalOutbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->getTenantId(),
            'event_id' => $event->getEventId(),
            'event_type' => $event->getEventName(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $event->getAggregateId(),
            'payload' => $event->toPayload(),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'occurred_at' => now(),
        ]);
    }
}
