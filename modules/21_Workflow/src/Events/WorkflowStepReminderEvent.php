<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A pending approval step passed the reminder time of its escalation rule: whoever it is assigned to should be nudged.
 * Notification channels subscribe to this event; the engine only records that the reminder was due.
 */
final readonly class WorkflowStepReminderEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    /**
     * Create the event.
     */
    public function __construct(
        public string $instanceId,
        public string $stepId,
        public ?string $assignedUserId,
        public ?string $assignedRole,
        public string $dueAt,
        public string $tenantId,
        public string $companyId
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'workflow.step.reminder';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'WorkflowInstanceStep';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateId(): string
    {
        return $this->stepId;
    }

    /**
     * {@inheritDoc}
     */
    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * {@inheritDoc}
     */
    public function getCompanyId(): string
    {
        return $this->companyId;
    }

    /**
     * {@inheritDoc}
     */
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
