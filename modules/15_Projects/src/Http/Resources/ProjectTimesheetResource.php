<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * API representation of a timesheet (schema ProjectsTimesheet).
 *
 * @mixin ProjectTimesheet
 */
final class ProjectTimesheetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProjectTimesheet $timesheet */
        $timesheet = $this->resource;

        return [
            'id' => $timesheet->id,
            'project_id' => $timesheet->project_id,
            'project_task_id' => $timesheet->project_task_id,
            'user_id' => $timesheet->user_id,
            'role' => $timesheet->role,
            'work_date' => $timesheet->work_date->toDateString(),
            'hours_worked' => (string) $timesheet->hours_worked,
            'overtime_hours' => (string) $timesheet->overtime_hours,
            'is_billable' => $timesheet->is_billable,
            'cost_rate' => (string) $timesheet->cost_rate,
            'charge_rate' => (string) $timesheet->charge_rate,
            'total_cost' => (string) $timesheet->total_cost,
            'total_charge' => (string) $timesheet->total_charge,
            'status' => $timesheet->status,
            'submitted_at' => $timesheet->submitted_at?->toIso8601String(),
            'approved_by' => $timesheet->approved_by,
            'approved_at' => $timesheet->approved_at?->toIso8601String(),
            'rejection_reason' => $timesheet->rejection_reason,
            'invoice_id' => $timesheet->invoice_id,
            'is_locked' => ! $timesheet->isEditable(),
            'work_summary' => $timesheet->work_summary,
        ];
    }
}
