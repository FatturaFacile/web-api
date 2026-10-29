<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkflowStepAssignedEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $instanceId,
        public string $stepId,
        public ?string $assignedUserId,
        public ?string $assignedRole,
        public string $dueAt,
        public string $tenantId,
        public string $companyId
    ) {}

    public function getEventName(): string
    {
        return 'workflow.step.assigned';
    }

    public function getAggregateType(): string
    {
        return 'WorkflowInstanceStep';
    }

    public function getAggregateId(): string
    {
        return $this->stepId;
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
            'step_id' => $this->stepId,
            'assigned_user_id' => $this->assignedUserId,
            'assigned_role' => $this->assignedRole,
            'due_at' => $this->dueAt,
        ];
    }
}
