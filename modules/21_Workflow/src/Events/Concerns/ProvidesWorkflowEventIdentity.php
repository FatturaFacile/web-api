<?php

declare(strict_types=1);

namespace Modules\Workflow\Events\Concerns;

/**
 * The aggregate, tenant and company getters every workflow domain event shares. The using event declares public
 * `$aggregateId`, `$tenantId` and `$companyId` properties.
 */
trait ProvidesWorkflowEventIdentity
{
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
        return $this->aggregateId;
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
}
