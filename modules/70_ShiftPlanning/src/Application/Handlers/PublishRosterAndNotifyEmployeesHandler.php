<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ShiftPlanning\Application\Commands\PublishRosterAndNotifyEmployeesCommand;
use Modules\ShiftPlanning\Application\Services\ShiftComplianceValidator;
use Modules\ShiftPlanning\Domain\Contracts\NotificationPort;
use Modules\ShiftPlanning\Domain\Contracts\TimeAndPayrollIntegrationPort;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftComplianceException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Domain\ValueObjects\ComplianceIssue;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;

/**
 * Publishes a draft roster. Every assigned shift is checked once more (leave, certificates and the plan of the employee may
 * have changed since it was saved): the baseline compliance is stored with the shift, and a blocking finding stops the
 * publication. The notifications and the payroll events leave after the commit.
 */
final class PublishRosterAndNotifyEmployeesHandler
{
    public function __construct(
        private readonly ShiftComplianceValidator $validator,
        private readonly NotificationPort $notifications,
        private readonly TimeAndPayrollIntegrationPort $payroll,
    ) {}

    /**
     * @return array{period: RosterSchedulePeriod, assigned: int, open: int, warnings: int, employee_ids: list<string>}
     *
     * @throws ShiftComplianceException
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(PublishRosterAndNotifyEmployeesCommand $command): array
    {
        $summary = DB::transaction(function () use ($command): array {
            $period = RosterSchedulePeriod::query()->where('tenant_id', $command->tenantId)->whereKey($command->periodId)->lockForUpdate()->first()
                ?? throw new ShiftNotFoundException('Unknown roster period.');

            $blocking = [];
            $warnings = 0;
            $open = 0;
            $assigned = 0;
            $employees = [];

            foreach ($period->assignments()->with('template')->orderBy('start_at')->get() as $assignment) {
                if ($assignment->employee_id === null) {
                    $open++;

                    continue;
                }

                $report = $this->validator->validate($command->tenantId, $period->jurisdiction, $assignment->toPlannedShift(), $assignment->required_role_or_skill_id);
                $assignment->update([
                    'estimated_labor_cost' => $report->estimatedLaborCost,
                    'surcharge_breakdown' => $report->surcharges->toArray(),
                    'compliance_warnings' => $report->issuesAsArray(),
                ]);
                $assigned++;
                $warnings += count($report->warnings());
                $employees[$assignment->employee_id] = true;

                foreach ($report->blocking() as $issue) {
                    $blocking[] = $this->located($issue, $assignment);
                }
            }

            if ($blocking !== []) {
                // The refreshed findings stay with the drafts only if the planner can act on them: roll back and report.
                throw new ShiftComplianceException($blocking, 'The roster has blocking compliance issues and cannot be published.');
            }

            $period->publish($command->userId);

            return ['period' => $period, 'assigned' => $assigned, 'open' => $open, 'warnings' => $warnings, 'employee_ids' => array_keys($employees)];
        });

        $period = $summary['period'];

        foreach ($summary['employee_ids'] as $employeeId) {
            $this->notifications->notifyEmployee($command->tenantId, $employeeId, 'SHIFT_PLANNING.NOTIFY_ROSTER_PUBLISHED', [
                'from' => $period->start_date->format('Y-m-d'),
                'to' => $period->end_date->format('Y-m-d'),
            ]);
        }

        $this->payroll->rosterPublished($period);

        return $summary;
    }

    private function located(ComplianceIssue $issue, ScheduledShiftAssignment $assignment): ComplianceIssue
    {
        return new ComplianceIssue($issue->severity, $issue->ruleCode, $issue->translationKey, [
            ...$issue->params,
            'assignment_id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'shift_date' => $assignment->shift_date->format('Y-m-d'),
        ]);
    }
}
