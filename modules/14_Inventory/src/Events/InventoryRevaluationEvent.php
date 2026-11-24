<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class InventoryRevaluationEvent implements DomainEventInterface
{
    /**
     * @param  array<string, mixed>  $adjustments
     */
    public function __construct(
        public string $revaluationId,
        public string $warehouseId,
        public string $revaluationMethod,
        public float $deltaAmount,
        public array $adjustments,
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
        return 'inventory_revaluation';
    }

    public function getAggregateId(): string
    {
        return $this->revaluationId;
    }

    public function getEventName(): string
    {
        return 'inventory.revaluation';
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
            'revaluation_id' => $this->revaluationId,
            'warehouse_id' => $this->warehouseId,
            'revaluation_method' => $this->revaluationMethod,
            'delta_amount' => $this->deltaAmount,
            'adjustments' => $this->adjustments,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
