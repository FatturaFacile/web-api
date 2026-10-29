<?php

declare(strict_types=1);

namespace Modules\HumanResources\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Modules\HumanResources\Contracts\LeaveAndTimeServiceInterface;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\LeaveRequest;
use Modules\HumanResources\Models\PerformanceReview;
use Modules\HumanResources\Models\Timesheet;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;

final readonly class WorkflowApprovalHRListener
{
    public function __construct(
        private LeaveAndTimeServiceInterface $leaveAndTimeService,
        private AuditLedgerInterface $auditLedger
    ) {}

    public function handleWorkflowApproved(WorkflowInstanceCompletedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $leaveRequest = LeaveRequest::where('workflow_instance_id', $instanceId)->first();
        if ($leaveRequest !== null && in_array($leaveRequest->status, ['pending_approval', 'submitted'], true)) {
            $leaveRequest->update(['is_locked' => false]);
            $this->leaveAndTimeService->approveLeaveRequest($leaveRequest, null);

            return;
        }

        $timesheet = Timesheet::where('workflow_instance_id', $instanceId)->first();
        if ($timesheet !== null && in_array($timesheet->status, ['pending_approval', 'submitted'], true)) {
            $timesheet->update(['is_locked' => false]);
            $this->leaveAndTimeService->approveTimesheet($timesheet, null);

            return;
        }

        $employee = Employee::where('workflow_instance_id', $instanceId)->first();
        if ($employee !== null) {
            $employee->update([
                'approval_status' => 'approved',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('HR_WORKFLOW_COMPLETED', $employee, null, ['workflow_instance_id' => $instanceId], 'MODULE_18_HR');

            return;
        }

        $review = PerformanceReview::where('workflow_instance_id', $instanceId)->first();
        if ($review !== null) {
            $review->update([
                'status' => 'completed',
                'is_locked' => false,
                'completed_at' => now(),
            ]);
            $this->auditLedger->record('HR_REVIEW_WORKFLOW_COMPLETED', $review, null, ['workflow_instance_id' => $instanceId], 'MODULE_18_HR');
        }
    }

    public function handleWorkflowRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $instanceId = $event->instanceId;

        $leaveRequest = LeaveRequest::where('workflow_instance_id', $instanceId)->first();
        if ($leaveRequest !== null && in_array($leaveRequest->status, ['pending_approval', 'submitted'], true)) {
            $leaveRequest->update(['is_locked' => false]);
            $this->leaveAndTimeService->rejectLeaveRequest($leaveRequest, 'Rejected via Workflow Engine V2', null);

            return;
        }

        $timesheet = Timesheet::where('workflow_instance_id', $instanceId)->first();
        if ($timesheet !== null) {
            $timesheet->update([
                'status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('HR_TIMESHEET_WORKFLOW_REJECTED', $timesheet, null, ['workflow_instance_id' => $instanceId], 'MODULE_18_HR');

            return;
        }

        $employee = Employee::where('workflow_instance_id', $instanceId)->first();
        if ($employee !== null) {
            $employee->update([
                'approval_status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('HR_WORKFLOW_REJECTED', $employee, null, ['workflow_instance_id' => $instanceId], 'MODULE_18_HR');

            return;
        }

        $review = PerformanceReview::where('workflow_instance_id', $instanceId)->first();
        if ($review !== null) {
            $review->update([
                'status' => 'rejected',
                'is_locked' => false,
            ]);
            $this->auditLedger->record('HR_REVIEW_WORKFLOW_REJECTED', $review, null, ['workflow_instance_id' => $instanceId], 'MODULE_18_HR');
        }
    }
}
