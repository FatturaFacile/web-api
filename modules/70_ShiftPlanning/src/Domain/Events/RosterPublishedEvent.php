<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Events;

/**
 * A roster was published. Time & Attendance (Mod 072) loads the expected hours, payroll (Mod 076 / 077) the surcharge
 * minutes of every assigned shift.
 */
final readonly class RosterPublishedEvent
{
    /**
     * @param  list<array<string, mixed>>  $shifts  one entry per assigned shift: assignment_id, employee_id, start_at, end_at, estimated_labor_cost, surcharge_breakdown
     */
    public function __construct(
        public string $tenantId,
        public string $periodId,
        public string $jurisdiction,
        public string $startDate,
        public string $endDate,
        public array $shifts,
    ) {}
}
