<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

use Modules\LeaveManagement\Models\LeaveRequest;
use Modules\LeaveManagement\Services\LeaveRequestWorkflowService;

final readonly class SubmitLeaveRequestHandler
{
    public function __construct(
        private LeaveRequestWorkflowService $workflowService,
    ) {}

    public function handle(SubmitLeaveRequestCommand $command): LeaveRequest
    {
        return $this->workflowService->submitLeaveRequest(
            tenantId: $command->tenantId,
            payload: $command->payload,
            actorUserId: $command->actorUserId,
        );
    }
}
