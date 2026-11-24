<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Listeners;

use Modules\LeaveManagement\Domain\Enums\LeaveRequestStatus;
use Modules\LeaveManagement\Models\LeaveRequest;
use Modules\LeaveManagement\Services\LeaveRequestWorkflowService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

/**
 * Syncs Mod 006 Workflow Engine approvals/rejections back to Mod 074 LeaveRequest records.
 */
final readonly class WorkflowApprovalLeaveListener
{
    public function __construct(
        private LeaveRequestWorkflowService $workflowService,
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $leaveRequest = LeaveRequest::query()
            ->where('workflow_instance_id', $event->instanceId)
            ->first();

        if ($leaveRequest !== null && $leaveRequest->status === LeaveRequestStatus::PENDING_APPROVAL->value) {
            $leaveRequest->update(['is_locked' => false]);
            $this->workflowService->approve($leaveRequest, null);
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $leaveRequest = LeaveRequest::query()
            ->where('workflow_instance_id', $event->instanceId)
            ->first();

        if ($leaveRequest !== null && $leaveRequest->status === LeaveRequestStatus::PENDING_APPROVAL->value) {
            $leaveRequest->update(['is_locked' => false]);
            $this->workflowService->reject($leaveRequest, 'Rejected via Workflow Engine (Mod 006)', null);
        }
    }
}
