<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkflowInstanceCompletedEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $instanceId,
        public string $status,
        public string $tenantId,
        public string $companyId,
        public string $definitionCode = '',
        public string $aggregateType = '',
        public string $aggregateId = ''
    ) {}

    public function getEventName(): string
    {
        return 'workflow.instance.completed';
    }

    public function getAggregateType(): string
    {
        return 'WorkflowInstance';
    }

    public function getAggregateId(): string
    {
        return $this->instanceId;
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
        return [
            'instance_id' => $this->instanceId,
            'status' => $this->status,
        ];
    }
}
