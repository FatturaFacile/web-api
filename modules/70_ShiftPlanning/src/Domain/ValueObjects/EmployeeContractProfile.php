<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\ValueObjects;

/**
 * What the planner needs from the employment contract and the skill register (Mod 068).
 */
final readonly class EmployeeContractProfile
{
    /**
     * @param  list<string>  $skillIds  skill ids the employee holds: a shift that requires a skill needs one of them
     */
    public function __construct(
        public string $employeeId,
        public string $fullName,
        public float $weeklyHours,
        public float $hourlyCost,
        public array $skillIds = [],
        public bool $regularNightWork = false,
    ) {}
}
