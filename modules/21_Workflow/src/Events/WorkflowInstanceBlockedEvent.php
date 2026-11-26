<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Workflow\Events\Concerns\ProvidesWorkflowEventIdentity;

/**
 * An instance cannot continue because a node has no approver that can be resolved (empty role, removed user, no matching
 * matrix tier). Nothing is approved implicitly: the administrators are alerted and decide how to go on.
 */
final readonly class WorkflowInstanceBlockedEvent implements DomainEventInterface
{
    use Dispatchable, ProvidesWorkflowEventIdentity;

    /**
     * Create the event.
     */
    public function __construct(
        public string $aggregateId,
        public string $reason,
        public string $tenantId,
        public string $companyId
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'workflow.instance.blocked';
    }

    /**
     * {@inheritDoc}
     */
    public function toPayload(): array
    {
        return ['instance_id' => $this->aggregateId, 'status' => 'blocked', 'reason' => $this->reason];
    }
}
