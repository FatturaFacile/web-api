<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\ValueObjects;

/**
 * What the planner needs to know about an employee with an active contract (Mod 068): the contractual week, the weekdays
 * worked (ISO 1 = Monday .. 7 = Sunday), the FTE ratio, the cost dimensions and where the employee works, which decides
 * the holiday calendar (a Swiss canton, an Italian province and municipality).
 */
final readonly class ResourceProfile
{
    /**
     * @param  list<int>  $workWeekdays
     */
    public function __construct(
        public string $employeeId,
        public string $fullName,
        public float $weeklyHours,
        public array $workWeekdays,
        public float $fteRatio,
        public float $hourlyCost,
        public ?string $departmentId,
        public ?string $departmentName,
        public ?string $costCenterId,
        public ?string $costCenterName,
        public string $country,
        public ?string $region,
        public ?string $municipality,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLookup(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'full_name' => $this->fullName,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'cost_center_id' => $this->costCenterId,
            'cost_center_name' => $this->costCenterName,
            'weekly_contract_hours' => round($this->weeklyHours, 2),
            'fte_ratio' => round($this->fteRatio, 2),
            'hourly_cost' => round($this->hourlyCost, 2),
            'country' => $this->country,
            'region' => $this->region,
        ];
    }
}
