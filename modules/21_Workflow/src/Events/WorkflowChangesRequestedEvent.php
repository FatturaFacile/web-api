<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Workflow\Events\Concerns\ProvidesWorkflowEventIdentity;

/**
 * An approver sent the document back to the requester with a reason; the approval ends and the requester resubmits.
 */
final readonly class WorkflowChangesRequestedEvent implements DomainEventInterface
{
    use Dispatchable, ProvidesWorkflowEventIdentity;

    /**
     * Create the event.
     */
    public function __construct(
        public string $aggregateId,
        public string $requestedBy,
        public ?string $reason,
        public ?string $requesterId,
        public string $tenantId,
        public string $companyId
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'workflow.changes_requested';
    }

    /**
     * {@inheritDoc}
     */
    public function toPayload(): array
    {
        return ['instance_id' => $this->aggregateId, 'requested_by' => $this->requestedBy, 'reason' => $this->reason, 'requester_id' => $this->requesterId];
    }
}
