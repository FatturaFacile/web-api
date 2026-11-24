<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

use Modules\LeaveManagement\Exceptions\LeaveManagementException;
use Modules\LeaveManagement\Models\LeaveRequest;
use Modules\LeaveManagement\Services\LeaveRequestWorkflowService;

final readonly class DecideLeaveRequestHandler
{
    public function __construct(
        private LeaveRequestWorkflowService $workflowService,
    ) {}

    public function handle(DecideLeaveRequestCommand $command): LeaveRequest
    {
        $request = LeaveRequest::query()
            ->where('tenant_id', $command->tenantId)
            ->findOrFail($command->leaveRequestId);

        return match ($command->decision) {
            'approve' => $this->workflowService->approve($request, $command->actorUserId),
            'reject' => $this->workflowService->reject($request, $command->reason ?? 'Rejected by manager', $command->actorUserId),
            'cancel' => $this->workflowService->cancel($request, $command->reason ?? 'Cancelled by requester', $command->actorUserId),
            default => throw LeaveManagementException::validation('invalid_decision', ['decision' => $command->decision]),
        };
    }
}
