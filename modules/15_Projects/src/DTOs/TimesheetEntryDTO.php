<?php

declare(strict_types=1);

namespace Modules\Projects\DTOs;

/**
 * Hours to log on a project. Rates are resolved from the rate cards unless a manager states them explicitly.
 */
final readonly class TimesheetEntryDTO
{
    public function __construct(
        public string $projectId,
        public string $userId,
        public string $workDate,
        public string $hoursWorked,
        public ?string $taskId = null,
        public string $overtimeHours = '0',
        public bool $isBillable = true,
        public ?string $customCostRate = null,
        public ?string $customChargeRate = null,
        public ?string $workSummary = null,
        public ?string $role = null,
        public bool $submit = false,
    ) {}
}
