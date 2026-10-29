<?php

declare(strict_types=1);

namespace Modules\Workflow\DTOs;

final readonly class WorkflowStepAssignmentDTO
{
    public function __construct(
        public string $workflowInstanceId,
        public string $workflowNodeId,
        public ?string $assignedUserId = null,
        public ?string $assignedRole = null,
        public int $tierLevel = 1,
        public ?int $timeoutHours = null
    ) {}
}
