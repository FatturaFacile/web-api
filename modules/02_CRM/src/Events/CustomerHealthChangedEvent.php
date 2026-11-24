<?php

declare(strict_types=1);

namespace Modules\CRM\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class CustomerHealthChangedEvent implements DomainEventInterface
{
    public function __construct(
        public string $accountId,
        public float $healthIndex,
        public string $churnRiskIndicator,
        public float $activityScore,
        public float $engagementScore,
        public float $revenueScore,
        public float $riskScore,
        public string $tenantId,
        public string $companyId,
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'crm_account';
    }

    public function getAggregateId(): string
    {
        return $this->accountId;
    }

    public function getEventName(): string
    {
        return 'crm.customer_health.changed';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function toPayload(): array
    {
        return get_object_vars($this);
    }
}
