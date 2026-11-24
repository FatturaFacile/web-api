<?php

declare(strict_types=1);

namespace Modules\HumanResources\DTOs;

final readonly class RecordTimesheetDTO
{
    public function __construct(
        public string $tenantId,
        public string $employeeId,
        public string $workDate,
        public float $regularHours,
        public float $overtimeHours = 0.0,
        public float $billableHours = 0.0,
        public ?string $projectId = null,
        public ?string $taskId = null,
        public ?string $costCenterId = null,
        public ?string $description = null
    ) {}
}
