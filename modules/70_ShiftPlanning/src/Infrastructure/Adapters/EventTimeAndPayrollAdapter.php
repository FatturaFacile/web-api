<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Adapters;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\ShiftPlanning\Domain\Contracts\TimeAndPayrollIntegrationPort;
use Modules\ShiftPlanning\Domain\Events\RosterPublishedEvent;
use Modules\ShiftPlanning\Domain\Events\ShiftAssignmentFinalizedEvent;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * Publishes the plan to Time & Attendance (Mod 072) and payroll (Mod 076 / 077) as Laravel events with the surcharge
 * breakdown of every shift.
 */
final class EventTimeAndPayrollAdapter implements TimeAndPayrollIntegrationPort
{
    public function __construct(private readonly Dispatcher $events) {}

    public function rosterPublished(RosterSchedulePeriod $period): void
    {
        $shifts = $period->assignments()->whereNotNull('employee_id')->orderBy('start_at')->get()
            ->map(static fn (ScheduledShiftAssignment $assignment): array => [
                'assignment_id' => $assignment->id,
                'employee_id' => $assignment->employee_id,
                'start_at' => $assignment->start_at->toIso8601String(),
                'end_at' => $assignment->end_at->toIso8601String(),
                'estimated_labor_cost' => $assignment->estimated_labor_cost,
                'surcharge_breakdown' => $assignment->surcharges()->toArray(),
            ])->values()->all();

        $this->events->dispatch(new RosterPublishedEvent(
            (string) $period->tenant_id,
            $period->id,
            $period->jurisdiction->value,
            $period->start_date->format('Y-m-d'),
            $period->end_date->format('Y-m-d'),
            $shifts,
        ));
    }

    public function assignmentFinalized(ScheduledShiftAssignment $assignment): void
    {
        $this->events->dispatch(new ShiftAssignmentFinalizedEvent(
            (string) $assignment->tenant_id,
            $assignment->id,
            $assignment->roster_schedule_period_id,
            (string) $assignment->employee_id,
            $assignment->start_at->toIso8601String(),
            $assignment->end_at->toIso8601String(),
            $assignment->estimated_labor_cost,
            $assignment->surcharges()->toArray(),
        ));
    }
}
