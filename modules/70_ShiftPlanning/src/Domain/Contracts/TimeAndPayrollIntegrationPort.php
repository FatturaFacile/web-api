<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Domain\Contracts;

use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * Time & Attendance (Mod 072) and payroll (Mod 076 Swiss, Mod 077 Italian): the surcharge breakdown of the plan leaves
 * as integration events, so the consumers need no knowledge of the planner.
 */
interface TimeAndPayrollIntegrationPort
{
    public function rosterPublished(RosterSchedulePeriod $period): void;

    public function assignmentFinalized(ScheduledShiftAssignment $assignment): void;
}
