<?php

declare(strict_types=1);

namespace Modules\Procurement\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class SupplierContractExpiredEvent implements DomainEventInterface
{
    public function __construct(
        public string $contractId,
        public string $contractNumber,
        public string $supplierId,
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
        return 'supplier_contract';
    }

    public function getAggregateId(): string
    {
        return $this->contractId;
    }

    public function getEventName(): string
    {
        return 'procurement.contract.expired';
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    public function getOccurredAt(): string
    {
        return $this->occurredAt !== '' ? $this->occurredAt : now()->toIso8601String();
    }

    public function toPayload(): array
    {
        return [
            'contract_id' => $this->contractId,
            'contract_number' => $this->contractNumber,
            'supplier_id' => $this->supplierId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
