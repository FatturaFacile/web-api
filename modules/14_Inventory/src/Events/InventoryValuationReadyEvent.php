<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryValuationReadyEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $valuationSummary
     */
    public function __construct(
        public string $valuationId,
        public string $warehouseId,
        public string $period,
        public float $totalValuationAmount,
        public string $valuationMethod,
        public array $valuationSummary,
        public string $companyId,
        public string $tenantId = '',
        public string $eventId = '',
        public string $occurredAt = ''
    ) {}

    public function getEventId(): string
    {
        return $this->eventId !== '' ? $this->eventId : (string) Str::uuid7();
    }

    public function getAggregateType(): string
    {
        return 'inventory_valuation';
    }

    public function getAggregateId(): string
    {
        return $this->valuationId;
    }

    public function getEventName(): string
    {
        return 'inventory.valuation_ready';
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
            'valuation_id' => $this->valuationId,
            'warehouse_id' => $this->warehouseId,
            'period' => $this->period,
            'total_valuation_amount' => $this->totalValuationAmount,
            'valuation_method' => $this->valuationMethod,
            'valuation_summary' => $this->valuationSummary,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
