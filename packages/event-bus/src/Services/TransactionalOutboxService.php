<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Services;

use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;
use WeakMap;

final class TransactionalOutboxService
{
    /**
     * Outbox rows already written for an event instance in this process, so that the same
     * event object reaching both a publisher and an outbox listener is recorded only once.
     *
     * @var WeakMap<DomainEventInterface, TransactionalOutbox>|null
     */
    private static ?WeakMap $recordedEvents = null;

    public function record(DomainEventInterface $event): TransactionalOutbox
    {
        self::$recordedEvents ??= new WeakMap;

        if (isset(self::$recordedEvents[$event])) {
            return self::$recordedEvents[$event];
        }

        $rawTenantId = $event->getTenantId();
        $tenantId = ! empty($rawTenantId) && Str::isUuid($rawTenantId)
            ? $rawTenantId
            : (app(TenantContext::class)->getTenantId() ?: null);

        $rawCompanyId = $event->getCompanyId();
        $companyId = ! empty($rawCompanyId) && Str::isUuid($rawCompanyId)
            ? $rawCompanyId
            : $tenantId;

        $outbox = TransactionalOutbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'event_type' => $event->getEventName(),
            'aggregate_type' => $event->getAggregateType(),
            'aggregate_id' => $event->getAggregateId(),
            'payload_json' => $event->toPayload(),
            'occurred_at' => now(),
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        self::$recordedEvents[$event] = $outbox;

        return $outbox;
    }
}
