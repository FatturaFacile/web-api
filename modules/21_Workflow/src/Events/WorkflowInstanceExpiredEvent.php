<?php

declare(strict_types=1);

namespace Modules\Workflow\Events;

use Enterprise\EventBus\Contracts\DomainEventInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An instance ended because nobody decided in time and its definition has no path for a timeout. The approved record
 * goes back to its state before the approval was requested.
 */
final readonly class WorkflowInstanceExpiredEvent implements DomainEventInterface
{
    use Dispatchable, SerializesModels;

    /**
     * Create the event.
     */
    public function __construct(
        public string $instanceId,
        public string $reason,
        public string $tenantId,
        public string $companyId
    ) {}

    /**
     * {@inheritDoc}
     */
    public function getEventName(): string
    {
        return 'workflow.instance.expired';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateType(): string
    {
        return 'WorkflowInstance';
    }

    /**
     * {@inheritDoc}
     */
    public function getAggregateId(): string
    {
        return $this->instanceId;
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
        return ['instance_id' => $this->instanceId, 'status' => 'expired', 'reason' => $this->reason];
    }
}
