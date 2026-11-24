<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Application\Commands;

use Modules\LeaveManagement\Models\LeaveRequest;
use Modules\LeaveManagement\Services\LeaveRequestWorkflowService;

final readonly class RegisterSickLeaveWithInpsProtocolHandler
{
    public function __construct(
        private LeaveRequestWorkflowService $workflowService,
    ) {}

    public function handle(RegisterSickLeaveWithInpsProtocolCommand $command): LeaveRequest
    {
        return $this->workflowService->registerSickOrAccidentLeave(
            tenantId: $command->tenantId,
            payload: $command->payload,
            actorUserId: $command->actorUserId,
        );
    }
}
