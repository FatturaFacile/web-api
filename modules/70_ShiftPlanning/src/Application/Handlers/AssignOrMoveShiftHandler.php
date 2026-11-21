<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\ShiftPlanning\Application\Commands\AssignOrMoveShiftCommand;
use Modules\ShiftPlanning\Application\DTOs\AssignmentResult;
use Modules\ShiftPlanning\Application\Services\ShiftAssignmentWriter;
use Modules\ShiftPlanning\Domain\Contracts\NotificationPort;
use Modules\ShiftPlanning\Domain\Contracts\TimeAndPayrollIntegrationPort;
use Modules\ShiftPlanning\Domain\Enums\RosterStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftComplianceException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftNotFoundException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;
use Modules\ShiftPlanning\Infrastructure\Models\RosterSchedulePeriod;
use Modules\ShiftPlanning\Infrastructure\Models\ScheduledShiftAssignment;
use Modules\ShiftPlanning\Infrastructure\Models\ShiftTemplate;

/**
 * Assign an open shift or drag-and-drop a shift to another employee or day. The whole check runs inside the transaction on
 * the locked period; a blocking rule answers 422 with the rule codes and nothing is saved, warnings are saved and returned
 * with the recalculated labour cost. On a published roster the change reaches the employees and payroll after the commit.
 */
final class AssignOrMoveShiftHandler
{
    public function __construct(
        private readonly ShiftAssignmentWriter $writer,
        private readonly NotificationPort $notifications,
        private readonly TimeAndPayrollIntegrationPort $payroll,
    ) {}

    /**
     * @throws ShiftComplianceException
     * @throws ShiftNotFoundException
     * @throws ShiftPlanningException
     */
    public function handle(AssignOrMoveShiftCommand $command): AssignmentResult
    {
        /** @var array{0: AssignmentResult, 1: RosterStatus} $outcome */
        $outcome = DB::transaction(function () use ($command): array {
            $existing = $command->assignmentId === null ? null : ScheduledShiftAssignment::query()
                ->where('tenant_id', $command->tenantId)->whereKey($command->assignmentId)->lockForUpdate()->first()
                ?? throw new ShiftNotFoundException('Unknown shift assignment.');

            $period = RosterSchedulePeriod::query()
                ->where('tenant_id', $command->tenantId)
                ->whereKey($existing->roster_schedule_period_id ?? $command->periodId)
                ->lockForUpdate()
                ->first() ?? throw new ShiftNotFoundException('Unknown roster period.');

            $period->assertEditable();

            if (! $period->containsDate($command->shiftDate)) {
                throw new ShiftPlanningException('The shift date is outside the roster period.');
            }

            $template = ShiftTemplate::query()->where('tenant_id', $command->tenantId)->where('is_active', true)->whereKey($command->shiftTemplateId)->first()
                ?? throw new ShiftNotFoundException('Unknown or inactive shift template.');

            $skillId = $command->requiredRoleOrSkillId ?? $existing?->required_role_or_skill_id;

            return [$this->writer->write($period, $template, $command->shiftDate, $command->employeeId, $skillId, $existing, true), $period->status];
        });

        [$result, $status] = $outcome;

        if ($status === RosterStatus::Published && $result->assignment->employee_id !== null) {
            $this->payroll->assignmentFinalized($result->assignment);
            $this->notifications->notifyEmployee($command->tenantId, $result->assignment->employee_id, 'SHIFT_PLANNING.NOTIFY_SHIFT_ASSIGNED', ['date' => $result->assignment->shift_date->format('Y-m-d')]);
        }

        if ($status === RosterStatus::Published && $result->previousEmployeeId !== null && $result->previousEmployeeId !== $result->assignment->employee_id) {
            $this->notifications->notifyEmployee($command->tenantId, $result->previousEmployeeId, 'SHIFT_PLANNING.NOTIFY_SHIFT_REMOVED', ['date' => $result->assignment->shift_date->format('Y-m-d')]);
        }

        return $result;
    }
}
