<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

final readonly class TimesheetEntryDTO
{
    public function __construct(
        public string $projectId,
        public string $userId,
        public string $workDate,
        public float $hoursWorked,
        public ?string $taskId = null,
        public float $overtimeHours = 0.00,
        public bool $isBillable = true,
        public ?float $customCostRate = null,
        public ?float $customChargeRate = null,
        public ?string $workSummary = null,
        public ?string $approverUserId = null
    ) {}
}
