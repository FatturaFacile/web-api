<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Modules\Workflow\Events\Concerns\ProvidesWorkflowEventIdentity;

/**
 * The requester withdrew an approval before anybody decided on it.
 */
final readonly class WorkflowInstanceWithdrawnEvent implements DomainEventInterface
{
    use Dispatchable, ProvidesWorkflowEventIdentity;

    /**
     * Create the event.
     */
    public function __construct(
        public string $aggregateId,
        public string $withdrawnBy,
        public string $tenantId,
        public string $companyId
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'workflow.instance.withdrawn';
    }

    /**
     * {@inheritDoc}
     */
    public function toPayload(): array
    {
        return ['instance_id' => $this->aggregateId, 'status' => 'withdrawn', 'withdrawn_by' => $this->withdrawnBy];
    }
}
