<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Workflow\Events\Concerns\ProvidesWorkflowEventIdentity;

/**
 * A delegation was created: the delegate now sees the approvals of the delegator within the chosen scope.
 */
final readonly class WorkflowDelegationActivatedEvent implements DomainEventInterface
{
    use Dispatchable, ProvidesWorkflowEventIdentity;

    /**
     * Create the event.
     */
    public function __construct(
        public string $aggregateId,
        public string $delegatorUserId,
        public string $delegateUserId,
        public string $startsAt,
        public string $endsAt,
        public string $tenantId,
        public string $companyId
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'WorkflowDelegation';
    }

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'workflow.delegation.activated';
    }

    /**
     * {@inheritDoc}
     */
    public function toPayload(): array
    {
        return [
            'delegation_id' => $this->aggregateId,
            'delegator_user_id' => $this->delegatorUserId,
            'delegate_user_id' => $this->delegateUserId,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }
}
