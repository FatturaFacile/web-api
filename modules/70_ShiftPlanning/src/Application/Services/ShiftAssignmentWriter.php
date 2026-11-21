<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Services;

use Carbon\CarbonImmutable;
use Modules\ShiftPlanning\Application\DTOs\AssignmentResult;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftComplianceException;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

/**
 * Builds, checks and stores one assignment. Callers run it inside a transaction.
 */
final class ShiftAssignmentWriter
{
    public function __construct(private readonly ShiftComplianceValidator $validator) {}

    /**
     * @param  bool  $rejectBlocking  true throws ShiftComplianceException before anything is stored; false stores the shift with its
     *                                blocking issues (generated drafts are corrected by the planner afterwards)
     *
     * @throws ShiftComplianceException
     */
    public function write(
        RosterSchedulePeriod $period,
        ShiftTemplate $template,
        CarbonImmutable $date,
        ?string $employeeId,
        ?string $requiredSkillId,
        ?ScheduledShiftAssignment $existing,
        bool $rejectBlocking,
    ): AssignmentResult {
        [$start, $end] = $template->instantsOn($date, $period->jurisdiction);
        $planned = new PlannedShift($existing?->id, $employeeId ?? '', $start, $end, $template->break_minutes, $template->is_on_call, $template->night_surcharge_applicable);
        $report = null;

        if ($employeeId !== null) {
            $report = $this->validator->validate((string) $period->tenant_id, $period->jurisdiction, $planned, $requiredSkillId);

            if ($rejectBlocking && $report->hasBlocking()) {
                throw new ShiftComplianceException($report->issues);
            }
        }

        $surcharges = $report->surcharges ?? $this->validator->surchargesOnly($period->jurisdiction, $planned);
        $previousEmployeeId = $existing?->employee_id;
        $assignment = $existing ?? new ScheduledShiftAssignment;
        $assignment->fill([
            'tenant_id' => $period->tenant_id,
            'roster_schedule_period_id' => $period->id,
            'employee_id' => $employeeId,
            'shift_date' => $date->format('Y-m-d'),
            'shift_template_id' => $template->id,
            'start_at' => $start,
            'end_at' => $end,
            'required_role_or_skill_id' => $requiredSkillId,
            'estimated_labor_cost' => $report->estimatedLaborCost ?? '0.00',
            'surcharge_breakdown' => $surcharges->toArray(),
            'compliance_warnings' => $report?->issuesAsArray() ?? [],
        ])->save();
        $assignment->setRelation('template', $template);

        return new AssignmentResult($assignment, $report, $previousEmployeeId);
    }
}
