<?php

declare(strict_types=1);

namespace Modules\Inventory\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Support\Str;

final readonly class ProjectInventoryIssueEvent implements DomainEventInterface
{
    public function __construct(
        public string $issueId,
        public string $projectId,
        public string $taskId,
        public string $stockItemId,
        public float $quantity,
        public float $unitCost,
        public string $warehouseId,
        public ?string $binId,
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
        return 'project_issue';
    }

    public function getAggregateId(): string
    {
        return $this->issueId;
    }

    public function getEventName(): string
    {
        return 'inventory.project_issue_completed';
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
            'issue_id' => $this->issueId,
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'stock_item_id' => $this->stockItemId,
            'quantity' => $this->quantity,
            'unit_cost' => $this->unitCost,
            'warehouse_id' => $this->warehouseId,
            'bin_id' => $this->binId,
            'company_id' => $this->companyId,
            'tenant_id' => $this->tenantId,
        ];
    }
}
