<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

/**
 * The contract data the evaluation depends on. The defaults are those of the desktop employee record.
 */
final readonly class EmployeeTimeProfile
{
    /**
     * @param  string|null  $hireDate  The first local day, as Y-m-d.
     * @param  string|null  $terminationDate  The last local day, as Y-m-d.
     * @param  string|null  $agreementDate  The date of the agreement of art. 73a or 73b OLL 1, as Y-m-d.
     */
    public function __construct(
        public string $employeeId = '',
        public string $employeeName = '',
        public TimeRecordingMode $mode = TimeRecordingMode::Detailed,
        public float|int|string $legalMaxWeeklyHours = 45,
        public float|int|string $weeklyHours = 42,
        public int $workDaysPerWeek = 5,
        public ?string $hireDate = null,
        public ?string $terminationDate = null,
        public ?string $agreementDate = null,
    ) {}
}
