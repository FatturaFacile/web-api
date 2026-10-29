<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Modules\Workflow\Models\WorkflowInstance;

interface LockableByWorkflowInterface
{
    /**
     * Renders the entity fiscally and operationally immutable.
     */
    public function lockByWorkflow(WorkflowInstance $instance): void;

    /**
     * Determines whether the entity is currently in an immutable workflow state.
     */
    public function isLockedByWorkflow(): bool;
}
