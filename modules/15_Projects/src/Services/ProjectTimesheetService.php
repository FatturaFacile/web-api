<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectTimesheetServiceInterface;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\DTOs\TimesheetEntryDTO;
use Modules\Projects\Events\ProjectTimesheetApprovedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Projects\Models\ProjectResource;
use Modules\Projects\Models\ProjectTimesheet;

final class ProjectTimesheetService implements ProjectTimesheetServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly AuditLedgerInterface $auditLedger,
        private readonly JobCostingAccumulationServiceInterface $costingService
    ) {}

    public function logTime(TimesheetEntryDTO $dto): ProjectTimesheet
    {
        return DB::transaction(function () use ($dto): ProjectTimesheet {
            /** @var Project $project */
            $project = Project::findOrFail($dto->projectId);

            // Resolve burdened cost and charge rate from ProjectResource if available
            $resource = ProjectResource::where('tenant_id', $project->tenant_id)
                ->where('user_id', $dto->userId)
                ->first();

            $costRate = $dto->customCostRate ?? ($resource ? (float) $resource->internal_hourly_cost : 50.00);
            $chargeRate = $dto->customChargeRate ?? ($resource ? (float) $resource->billing_hourly_rate : 90.00);

            // Burdened overtime calculation (1.5x cost rate for overtime)
            $regularHours = max(0.0, $dto->hoursWorked - $dto->overtimeHours);
            $totalCost = round(($regularHours * $costRate) + ($dto->overtimeHours * $costRate * 1.5), 2);
            $totalCharge = round(($dto->hoursWorked * $chargeRate), 2);

            $timesheet = ProjectTimesheet::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'project_task_id' => $dto->taskId,
                'user_id' => $dto->userId,
                'work_date' => $dto->workDate,
                'hours_worked' => $dto->hoursWorked,
                'overtime_hours' => $dto->overtimeHours,
                'is_billable' => $dto->isBillable,
                'cost_rate' => $costRate,
                'charge_rate' => $chargeRate,
                'total_cost' => $totalCost,
                'total_charge' => $totalCharge,
                'status' => 'submitted',
                'work_summary' => $dto->workSummary,
            ]);

            return $timesheet;
        });
    }

    public function approveTimesheet(ProjectTimesheet $timesheet, string $approvedBy): ProjectTimesheet
    {
        return DB::transaction(function () use ($timesheet, $approvedBy): ProjectTimesheet {
            $timesheet->loadMissing('project');
            $project = $timesheet->project;

            $timesheet->update([
                'status' => 'approved',
                'approved_by' => $approvedBy,
                'approved_at' => now(),
            ]);

            // Post labor direct cost into ProjectCostEntry
            $this->costingService->recordCost(new RecordJobCostDTO(
                projectId: (string) $project->id,
                costCategory: 'labour',
                costSourceType: 'Timesheet',
                costSourceId: (string) $timesheet->id,
                quantity: (float) $timesheet->hoursWorked,
                unitCost: (float) $timesheet->cost_rate,
                totalCost: (float) $timesheet->total_cost,
                incurredDate: (string) $timesheet->work_date,
                taskId: $timesheet->project_task_id,
                uom: 'HR',
                isBillable: (bool) $timesheet->is_billable,
                userId: $approvedBy
            ));

            // Emit Domain Event
            $this->dispatcher->publish(new ProjectTimesheetApprovedEvent(
                timesheetId: (string) $timesheet->id,
                projectId: (string) $project->id,
                userId: (string) $timesheet->user_id,
                hoursWorked: (float) $timesheet->hoursWorked,
                totalCost: (float) $timesheet->total_cost,
                totalCharge: (float) $timesheet->total_charge,
                approverUserId: $approvedBy,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            // Audit entry
            ProjectAuditEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'actor_user_id' => $approvedBy,
                'event_type' => 'timesheet_approved',
                'description' => "Approved timesheet for user {$timesheet->user_id} ({$timesheet->hoursWorked}h, €{$timesheet->total_cost})",
                'before_state' => ['status' => 'submitted'],
                'after_state' => $timesheet->toArray(),
            ]);

            return $timesheet;
        });
    }
}
