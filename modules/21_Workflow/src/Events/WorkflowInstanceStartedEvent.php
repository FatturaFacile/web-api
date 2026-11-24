<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkflowInstanceStartedEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $instanceId,
        public string $workflowCode,
        public string $approvableType,
        public string $approvableId,
        public string $tenantId,
        public string $companyId
    ) {}

    public function getEventName(): string
    {
        return 'workflow.instance.started';
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
            'workflow_code' => $this->workflowCode,
            'approvable_type' => $this->approvableType,
            'approvable_id' => $this->approvableId,
        ];
    }
}
