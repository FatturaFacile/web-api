<?php

declare(strict_types=1);

namespace Modules\Projects\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectMaterialConsumedEvent implements DomainEventInterface
{
    public function __construct(
        public string $projectId,
        public ?string $taskId,
        public string $stockItemId,
        public float $quantity,
        public float $unitCost,
        public float $totalCost,
        public ?string $warehouseBinId,
        public ?string $lotId,
        public ?string $serialNumberId,
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
        return 'project';
    }

    public function getAggregateId(): string
    {
        return $this->projectId;
    }

    public function getEventName(): string
    {
        return 'project.material_consumed';
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
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'stock_item_id' => $this->stockItemId,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'total_cost' => $this->totalCost,
            'warehouse_bin_id' => $this->warehouseBinId,
            'lot_id' => $this->lotId,
            'serial_number_id' => $this->serialNumberId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
        ];
    }
}
