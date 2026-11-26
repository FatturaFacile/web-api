<?php

declare(strict_types=1);

namespace Modules\Workflow\Exceptions;

use InvalidArgumentException;
use Modules\Workflow\Models\ApprovalDecision;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * A decision arrived for a step that is no longer pending: somebody else decided first (two approvers of an `any` node
 * acting at the same time) or the node was left. The API answers 409 and shows the decision that won.
 */
final class StepAlreadyDecidedException extends InvalidArgumentException
{
    /**
     * Create the exception for a step.
     */
    public function __construct(public readonly WorkflowInstanceStep $step, string $message)
    {
        parent::__construct($message);
    }

    /**
     * The decision that closed the step, as the 409 body shows it; null when the step was skipped or timed out.
     *
     * @return array{decision: string, decided_by: ?string, decided_at: ?string, step_status: string}
     */
    public function existingDecision(): array
    {
        // The decision that won: one of the steps of the same node (a skipped step has none of its own).
        $stepIds = WorkflowInstanceStep::query()
            ->where('workflow_instance_id', $this->step->workflow_instance_id)
            ->where('workflow_node_id', $this->step->workflow_node_id)
            ->pluck('id');

        /** @var ApprovalDecision|null $decision */
        $decision = ApprovalDecision::query()->whereIn('workflow_instance_step_id', $stepIds)->orderBy('decided_at')->first();

        return [
            'decision' => $decision === null ? (string) $this->step->status : $decision->decision,
            'decided_by' => $decision === null ? null : $decision->effective_user_id,
            'decided_at' => $decision?->decided_at?->toIso8601String(),
            'step_status' => (string) $this->step->status,
        ];
    }
}
