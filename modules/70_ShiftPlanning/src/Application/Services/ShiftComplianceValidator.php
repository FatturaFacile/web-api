<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Services;

use Carbon\CarbonInterface;
use Modules\ShiftPlanning\Application\DTOs\ComplianceReport;
use Modules\ShiftPlanning\Domain\Contracts\EmployeeContractPort;
use Modules\ShiftPlanning\Domain\Contracts\LeaveManagementPort;
use Modules\ShiftPlanning\Domain\Contracts\SafetyCertificationPort;
use Modules\ShiftPlanning\Domain\Enums\Jurisdiction;
use Modules\ShiftPlanning\Domain\Services\LaborComplianceStrategyResolver;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceContext;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Domain\ValueObjects\PlannedShift;
use Modules\ShiftPlanning\Domain\ValueObjects\SurchargeBreakdown;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * The real-time check behind every assignment: it joins the planned shifts of the employee with leave (Mod 074), the
 * contract and skills (Mod 068), the safety certificates (Mod 099) and the labour law of the jurisdiction, and prices the
 * shift. It reads, never writes.
 */
final class ShiftComplianceValidator
{
    /** Days of the employee's plan loaded on each side of the shift: enough for a weekly rest and 6 consecutive days. */
    private const int NEIGHBOUR_DAYS = 15;

    public function __construct(
        private readonly LaborComplianceStrategyResolver $strategies,
        private readonly LeaveManagementPort $leave,
        private readonly EmployeeContractPort $contracts,
        private readonly SafetyCertificationPort $certifications,
    ) {}

    /**
     * @param  list<string>  $excludeAssignmentIds  assignments left out of the employee's plan: the shift being moved and the counterpart of a swap
     */
    public function validate(string $tenantId, Jurisdiction $jurisdiction, PlannedShift $candidate, ?string $requiredSkillId, array $excludeAssignmentIds = []): ComplianceReport
    {
        $strategy = $this->strategies->for($jurisdiction);
        $timezone = $jurisdiction->timezone();
        $firstDay = $candidate->startAt->setTimezone($timezone)->startOfDay();
        $lastDay = $candidate->endAt->setTimezone($timezone)->startOfDay();
        $others = $this->otherShifts($tenantId, $candidate, $excludeAssignmentIds);
        $profile = $this->contracts->profilesFor([$candidate->employeeId], $firstDay)[$candidate->employeeId] ?? null;
        $issues = [];

        foreach ($others as $other) {
            if ($candidate->overlaps($other)) {
                $issues[] = ComplianceIssue::blocking('SCHEDULE.OVERLAP', ['conflicting_assignment_id' => $other->assignmentId]);
            }
        }

        foreach ($this->leave->conflictsFor($candidate->employeeId, $firstDay, $lastDay) as $leave) {
            $params = ['leave_request_id' => $leave->leaveRequestId, 'from' => $leave->startDate->format('Y-m-d'), 'to' => $leave->endDate->format('Y-m-d')];
            $issues[] = $leave->isApproved ? ComplianceIssue::blocking('LEAVE.APPROVED_CONFLICT', $params) : ComplianceIssue::warning('LEAVE.PENDING_CONFLICT', $params);
        }

        if ($profile === null) {
            $issues[] = ComplianceIssue::blocking('CONTRACT.NOT_FOUND', ['date' => $firstDay->format('Y-m-d')]);
        } else {
            array_push($issues, ...$this->contractIssues($candidate, $others, $profile->weeklyHours, $timezone));

            if ($requiredSkillId !== null && ! in_array($requiredSkillId, $profile->skillIds, true)) {
                $issues[] = ComplianceIssue::blocking('SKILL.MISSING', ['required_skill_id' => $requiredSkillId]);
            }
        }

        foreach ($this->certifications->invalidMandatoryCertifications($candidate->employeeId, $firstDay) as $certification) {
            $issues[] = ComplianceIssue::blocking('SAFETY.CERTIFICATION_INVALID', [
                'certification_code' => $certification->code,
                'certification_name' => $certification->name,
                'expired_on' => $certification->expiredOn?->format('Y-m-d'),
            ]);
        }

        $context = new ComplianceContext($profile->regularNightWork ?? false);
        array_push($issues, ...$strategy->validate($candidate, $others, $context));
        $surcharges = $strategy->calculateSurcharges($candidate, $context);

        return new ComplianceReport($issues, $surcharges, $this->price($surcharges, $profile->hourlyCost ?? 0.0));
    }

    /**
     * What the shift costs without a check: surcharges only, no employee.
     */
    public function surchargesOnly(Jurisdiction $jurisdiction, PlannedShift $shift): SurchargeBreakdown
    {
        return $this->strategies->for($jurisdiction)->calculateSurcharges($shift, new ComplianceContext);
    }

    /**
     * @param  list<string>  $excludeAssignmentIds
     * @return list<PlannedShift>
     */
    private function otherShifts(string $tenantId, PlannedShift $candidate, array $excludeAssignmentIds): array
    {
        $excluded = $candidate->assignmentId === null ? $excludeAssignmentIds : [...$excludeAssignmentIds, $candidate->assignmentId];

        return ScheduledShiftAssignment::query()
            ->with('template')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $candidate->employeeId)
            ->whereNotIn('id', $excluded)
            ->where('end_at', '>', $candidate->startAt->subDays(self::NEIGHBOUR_DAYS))
            ->where('start_at', '<', $candidate->endAt->addDays(self::NEIGHBOUR_DAYS))
            ->get()
            ->map(static fn (ScheduledShiftAssignment $assignment): PlannedShift => $assignment->toPlannedShift())
            ->values()
            ->all();
    }

    /**
     * The planned hours of the ISO week against the contractual hours.
     *
     * @param  list<PlannedShift>  $others
     * @return list<ComplianceIssue>
     */
    private function contractIssues(PlannedShift $candidate, array $others, float $weeklyHours, string $timezone): array
    {
        $weekStart = $candidate->startAt->setTimezone($timezone)->startOfWeek(CarbonInterface::MONDAY);
        $weekEnd = $weekStart->addWeek();
        $minutes = $candidate->workedMinutes();

        foreach ($others as $other) {
            $start = $other->startAt->setTimezone($timezone);

            if ($start >= $weekStart && $start < $weekEnd) {
                $minutes += $other->workedMinutes();
            }
        }

        if ($weeklyHours > 0 && $minutes > $weeklyHours * 60) {
            return [ComplianceIssue::warning('CONTRACT.WEEKLY_HOURS_EXCEEDED', [
                'scheduled_hours' => round($minutes / 60, 2),
                'contract_hours' => round($weeklyHours, 2),
                'week_start' => $weekStart->format('Y-m-d'),
            ])];
        }

        return [];
    }

    /**
     * Base pay plus the surcharges of the statute or the agreement. A Sunday that is also a holiday earns the higher of the
     * two supplements, never both; regular night work is paid back as time (the compensation minutes at the base rate).
     */
    private function price(SurchargeBreakdown $shift, float $hourlyCost): string
    {
        $base = $shift->workedMinutes / 60 * $hourlyCost;
        $night = $shift->nightMinutes / 60 * $hourlyCost * ($shift->nightWageSurchargePercent ?? 0.0) / 100;
        $compensation = $shift->nightTimeCompensationMinutes / 60 * $hourlyCost;
        $restDay = max(
            $shift->sundayMinutes / 60 * $hourlyCost * ($shift->sundayWageSurchargePercent ?? 0.0) / 100,
            $shift->holidayMinutes / 60 * $hourlyCost * ($shift->holidayWageSurchargePercent ?? 0.0) / 100,
        );
        $onCall = $shift->onCallMinutes / 60 * $hourlyCost * ($shift->onCallWageSurchargePercent ?? 0.0) / 100;

        return number_format(round($base + $night + $compensation + $restDay + $onCall, 2), 2, '.', '');
    }
}
