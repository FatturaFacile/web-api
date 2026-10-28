<?php

declare(strict_types=1);

namespace Modules\Workflow\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\DTOs\StartWorkflowDTO;
use Modules\Workflow\Models\WorkflowInstance;
use Modules\Workflow\Models\WorkflowInstanceStep;

interface WorkflowEngineInterface
{
    /**
     * Instantiates and starts a workflow instance for an approvable model.
     */
    public function start(StartWorkflowDTO $dto): WorkflowInstance;

    /**
     * Records an approval, rejection, or abstention decision on an active step.
     */
    public function recordDecision(RecordDecisionDTO $dto): WorkflowInstanceStep;

    /**
     * Evaluates transitions from current node and advances the state machine.
     */
    public function advance(WorkflowInstance $instance, string $actionTrigger): void;

    /**
     * Evaluates timeout rules and advances or escalates past-due steps.
     */
    public function processTimeouts(): int;

    /**
     * Cancels an active workflow instance with an audit note.
     */
    public function cancel(WorkflowInstance $instance, ?string $reason = null): void;
}
