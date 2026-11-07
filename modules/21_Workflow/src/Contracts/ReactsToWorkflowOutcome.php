<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Modules\Workflow\Models\WorkflowInstance;

interface ReactsToWorkflowOutcome
{
    /**
     * Called by the engine in the same transaction that ends the workflow.
     *
     * @param  string  $outcome  approved, rejected or cancelled
     */
    public function onWorkflowOutcome(WorkflowInstance $instance, string $outcome): void;
}
