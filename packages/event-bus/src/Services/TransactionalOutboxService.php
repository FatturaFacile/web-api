<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Services;

use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Support\Str;

final class TransactionalOutboxService
{
    public function record(DomainEventInterface $event): TransactionalOutbox
    {
        $rawTenantId = $event->getTenantId();
        $tenantId = ! empty($rawTenantId) && Str::isUuid($rawTenantId)
            ? $rawTenantId
            : (app(TenantContext::class)->getTenantId() ?: null);

        $rawCompanyId = $event->getCompanyId();
        $companyId = ! empty($rawCompanyId) && Str::isUuid($rawCompanyId)
            ? $rawCompanyId
            : $tenantId;

        return TransactionalOutbox::create([
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
    }
}
