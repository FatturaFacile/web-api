<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Events\ProjectTimesheetApprovedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Timesheet approval flow: draft, submitted, approved or rejected; a rejected sheet is corrected and submitted again.
 *
 * Rates come from the rate cards valid on the work date (ProjectRateResolver) unless a manager states them. Cost and
 * charge are hours times rate; overtime hours are recorded as part of the hours worked, no surcharge is assumed since
 * premiums depend on the employment contract. On approval the sheet is locked and its labour cost is booked on the
 * project through job costing.
 */
final readonly class ProjectTimesheetService implements ProjectTimesheetServiceInterface
{
    /** Hours a person can book on one calendar day across every project. */
    private const string MaxHoursPerDay = '24';

    public function __construct(
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
        private JobCostingAccumulationServiceInterface $costingService,
        private ProjectRateResolver $rates,
    ) {}

    /**
     * Logs hours as a draft, or directly submitted when asked.
     *
     * @throws ValidationException when the task, the hours or the rates do not fit
     * @throws ConflictHttpException when the project no longer accepts time
     */
    public function logTime(TimesheetEntryDTO $dto): ProjectTimesheet
    {
        return DB::transaction(function () use ($dto): ProjectTimesheet {
            $project = Project::query()->whereKey($dto->projectId)->firstOrFail();
            $this->assertProjectAcceptsTime($project);
            $this->assertTask($project, $dto->taskId);
            $this->assertHours($dto->userId, $dto->workDate, $dto->hoursWorked, $dto->overtimeHours, null);
            [$costRate, $chargeRate] = $this->rates($project, $dto->userId, $dto->role, $dto->workDate, $dto->customCostRate, $dto->customChargeRate);

            $timesheet = ProjectTimesheet::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'project_task_id' => $dto->taskId,
                'user_id' => $dto->userId,
                'role' => $dto->role,
                'work_date' => $dto->workDate,
                'hours_worked' => Money::amount($dto->hoursWorked),
                'overtime_hours' => Money::amount($dto->overtimeHours),
                'is_billable' => $dto->isBillable,
                'cost_rate' => Money::amount($costRate),
                'charge_rate' => Money::amount($chargeRate),
                'total_cost' => Money::amount(Money::mul($dto->hoursWorked, $costRate)),
                'total_charge' => Money::amount(Money::mul($dto->hoursWorked, $chargeRate)),
                'status' => $dto->submit ? 'submitted' : 'draft',
                'submitted_at' => $dto->submit ? now() : null,
                'work_summary' => $dto->workSummary,
            ]);

            return $timesheet;
        });
    }

    /**
     * Changes a draft or rejected sheet; rates and totals are recomputed.
     *
     * @param  array{work_date?: string, hours_worked?: string, overtime_hours?: string, task_id?: string|null, is_billable?: bool, work_summary?: string|null, role?: string|null, custom_cost_rate?: string|null, custom_charge_rate?: string|null}  $changes
     */
    public function updateTimesheet(ProjectTimesheet $timesheet, array $changes): ProjectTimesheet
    {
        return DB::transaction(function () use ($timesheet, $changes): ProjectTimesheet {
            $locked = $this->lockEditable($timesheet);
            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->assertProjectAcceptsTime($project);

            $workDate = $changes['work_date'] ?? $locked->work_date->toDateString();
            $hours = $changes['hours_worked'] ?? (string) $locked->hours_worked;
            $overtime = $changes['overtime_hours'] ?? (string) $locked->overtime_hours;
            $taskId = array_key_exists('task_id', $changes) ? $changes['task_id'] : $locked->project_task_id;
            $role = array_key_exists('role', $changes) ? $changes['role'] : $locked->role;

            $this->assertTask($project, $taskId);
            $this->assertHours((string) $locked->user_id, $workDate, $hours, $overtime, (string) $locked->id);
            [$costRate, $chargeRate] = $this->rates($project, (string) $locked->user_id, $role, $workDate, $changes['custom_cost_rate'] ?? null, $changes['custom_charge_rate'] ?? null);

            $locked->update([
                'work_date' => $workDate,
                'hours_worked' => Money::amount($hours),
                'overtime_hours' => Money::amount($overtime),
                'project_task_id' => $taskId,
                'role' => $role,
                'is_billable' => $changes['is_billable'] ?? $locked->is_billable,
                'work_summary' => array_key_exists('work_summary', $changes) ? $changes['work_summary'] : $locked->work_summary,
                'cost_rate' => Money::amount($costRate),
                'charge_rate' => Money::amount($chargeRate),
                'total_cost' => Money::amount(Money::mul($hours, $costRate)),
                'total_charge' => Money::amount(Money::mul($hours, $chargeRate)),
            ]);

            return $locked;
        });
    }

    /**
     * Sends a draft or rejected sheet for approval.
     */
    public function submitTimesheet(ProjectTimesheet $timesheet): ProjectTimesheet
    {
        return DB::transaction(function () use ($timesheet): ProjectTimesheet {
            $locked = $this->lockEditable($timesheet);
            $locked->update(['status' => 'submitted', 'submitted_at' => now(), 'rejection_reason' => null]);

            return $locked;
        });
    }

    /**
     * Deletes a draft or rejected sheet; approved hours stay on record.
     */
    public function deleteTimesheet(ProjectTimesheet $timesheet): void
    {
        DB::transaction(function () use ($timesheet): void {
            $this->lockEditable($timesheet)->delete();
        });
    }

    /**
     * Approves a submitted sheet, locks it and books its labour cost on the project.
     *
     * @throws ConflictHttpException when the sheet is not waiting for approval
     */
    public function approveTimesheet(ProjectTimesheet $timesheet, string $approvedBy): ProjectTimesheet
    {
        return DB::transaction(function () use ($timesheet, $approvedBy): ProjectTimesheet {
            $locked = ProjectTimesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'submitted') {
                throw new ConflictHttpException(__('projects_errors.timesheet.approve_only_submitted', ['status' => $locked->status]));
            }

            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->assertProjectAcceptsTime($project);

            $costEntry = $this->costingService->recordCost(new RecordJobCostDTO(
                projectId: (string) $project->id,
                costCategory: 'labour',
                costSourceType: 'Timesheet',
                costSourceId: (string) $locked->id,
                quantity: (string) $locked->hours_worked,
                unitCost: (string) $locked->cost_rate,
                totalCost: (string) $locked->total_cost,
                incurredDate: $locked->work_date->toDateString(),
                taskId: $locked->project_task_id,
                uom: 'HR',
                isBillable: (bool) $locked->is_billable,
                userId: $approvedBy,
                description: 'Timesheet '.$locked->work_date->toDateString(),
            ));

            $locked->update([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
                'project_cost_entry_id' => $costEntry->id,
            ]);

            $task = $locked->project_task_id !== null ? ProjectTask::query()->whereKey($locked->project_task_id)->lockForUpdate()->first() : null;
            $task?->update(['actual_hours' => Money::amount(Money::add($task->actual_hours, $locked->hours_worked))]);

            $this->dispatcher->publishAndDispatch(new ProjectTimesheetApprovedEvent(
                timesheetId: (string) $locked->id,
                projectId: (string) $project->id,
                userId: (string) $locked->user_id,
                hoursWorked: (float) $locked->hours_worked,
                totalCost: (float) $locked->total_cost,
                totalCharge: (float) $locked->total_charge,
                approverUserId: $approvedBy,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            $this->audit->record(
                $project,
                $approvedBy,
                'timesheet_approved',
                "Approved timesheet of user {$locked->user_id} ({$locked->hours_worked}h, cost {$locked->total_cost})",
                ['status' => 'submitted'],
                ['status' => 'approved', 'cost_entry_id' => $costEntry->id],
                $locked
            );

            return $locked;
        });
    }

    /**
     * Sends a submitted sheet back to its author with the reason.
     */
    public function rejectTimesheet(ProjectTimesheet $timesheet, string $rejectedBy, string $reason): ProjectTimesheet
    {
        return DB::transaction(function () use ($timesheet, $rejectedBy, $reason): ProjectTimesheet {
            $locked = ProjectTimesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'submitted') {
                throw new ConflictHttpException(__('projects_errors.timesheet.reject_only_submitted', ['status' => $locked->status]));
            }

            $locked->update(['status' => 'rejected', 'rejected_by' => $rejectedBy, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            $project = Project::query()->whereKey($locked->project_id)->firstOrFail();
            $this->audit->record($project, $rejectedBy, 'timesheet_rejected', "Rejected timesheet of user {$locked->user_id}: {$reason}", ['status' => 'submitted'], ['status' => 'rejected'], $locked);

            return $locked;
        });
    }

    private function lockEditable(ProjectTimesheet $timesheet): ProjectTimesheet
    {
        $locked = ProjectTimesheet::query()->whereKey($timesheet->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isEditable()) {
            throw new ConflictHttpException(__('projects_errors.timesheet.locked', ['status' => $locked->status]));
        }

        return $locked;
    }

    private function assertProjectAcceptsTime(Project $project): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException(__('projects_errors.timesheet.project_closed', ['number' => $project->project_number, 'status' => $project->status]));
        }
    }

    private function assertTask(Project $project, ?string $taskId): void
    {
        if ($taskId !== null && ! ProjectTask::query()->whereKey($taskId)->where('project_id', $project->id)->exists()) {
            throw ValidationException::withMessages(['task_id' => __('projects_errors.task.not_in_project')]);
        }
    }

    /**
     * Hours must be positive, overtime within the hours, and the day of the person cannot exceed 24 booked hours.
     */
    private function assertHours(string $userId, string $workDate, string $hours, string $overtime, ?string $ignoreTimesheetId): void
    {
        if (! Money::isPositive($hours) || Money::compare($hours, self::MaxHoursPerDay) > 0) {
            throw ValidationException::withMessages(['hours_worked' => __('projects_errors.timesheet.hours_range')]);
        }

        if (Money::isNegative($overtime) || Money::compare($overtime, $hours) > 0) {
            throw ValidationException::withMessages(['overtime_hours' => __('projects_errors.timesheet.overtime_range')]);
        }

        $booked = (string) ProjectTimesheet::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', $workDate)
            ->where('status', '!=', 'rejected')
            ->when($ignoreTimesheetId !== null, fn ($query) => $query->whereKeyNot($ignoreTimesheetId))
            ->sum('hours_worked');

        if (Money::compare(Money::add($booked, $hours), self::MaxHoursPerDay) > 0) {
            throw ValidationException::withMessages(['hours_worked' => __('projects_errors.timesheet.day_exceeds_24', ['hours' => $booked, 'date' => $workDate])]);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function rates(Project $project, string $userId, ?string $role, string $workDate, ?string $customCost, ?string $customCharge): array
    {
        if ($customCost !== null && $customCharge !== null) {
            return [Money::of($customCost), Money::of($customCharge)];
        }

        $resolved = $this->rates->resolve($project, $userId, $role, $workDate);

        if ($resolved === null) {
            throw ValidationException::withMessages(['user_id' => __('projects_errors.timesheet.rate_missing')]);
        }

        return [Money::of($customCost ?? $resolved['cost_rate']), Money::of($customCharge ?? $resolved['bill_rate'])];
    }
}
