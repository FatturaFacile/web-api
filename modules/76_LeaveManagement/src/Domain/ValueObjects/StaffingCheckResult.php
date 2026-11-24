<?php

declare(strict_types=1);

namespace Modules\LeaveManagement\Domain\ValueObjects;

/**
 * Result of checking department minimum staffing thresholds (presidio minimo) over a date range.
 */
final readonly class StaffingCheckResult
{
    /**
     * @param  list<array{date: string, present_count: int, absent_count: int, required_min: int}>  $criticalDays
     */
    public function __construct(
        public bool $breached,
        public ?string $departmentId,
        public ?string $departmentName,
        public int $totalActiveEmployees,
        public int $minPresentCount,
        public float $minPresentPercent,
        public int $effectiveMinRequired,
        public bool $enforceHardBlock,
        public array $criticalDays,
    ) {}

    /**
     * @return array{
     *     breached: bool,
     *     department_id: string|null,
     *     department_name: string|null,
     *     total_active_employees: int,
     *     min_present_count: int,
     *     min_present_percent: float,
     *     effective_min_required: int,
     *     enforce_hard_block: bool,
     *     critical_days: list<array{date: string, present_count: int, absent_count: int, required_min: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'breached' => $this->breached,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'total_active_employees' => $this->totalActiveEmployees,
            'min_present_count' => $this->minPresentCount,
            'min_present_percent' => $this->minPresentPercent,
            'effective_min_required' => $this->effectiveMinRequired,
            'enforce_hard_block' => $this->enforceHardBlock,
            'critical_days' => $this->criticalDays,
        ];
    }
}
