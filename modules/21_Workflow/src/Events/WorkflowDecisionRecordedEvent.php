<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final readonly class WorkflowDecisionRecordedEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $instanceId,
        public string $stepId,
        public string $decision,
        public string $userId,
        public string $tenantId,
        public string $companyId
    ) {}

    public function getEventName(): string
    {
        return 'workflow.decision.recorded';
    }

    public function getAggregateType(): string
    {
        return 'ApprovalDecision';
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
            'decision' => $this->decision,
            'user_id' => $this->userId,
        ];
    }
}
