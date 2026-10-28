<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Projects\Models\ProjectTimesheet;

/**
 * @mixin ProjectTimesheet
 */
final class ProjectTimesheetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_task_id' => $this->project_task_id,
            'user_id' => $this->user_id,
            'work_date' => $this->work_date?->toDateString(),
            'hours_worked' => (float) $this->hours_worked,
            'overtime_hours' => (float) $this->overtime_hours,
            'is_billable' => (bool) $this->is_billable,
            'cost_rate' => (float) $this->cost_rate,
            'charge_rate' => (float) $this->charge_rate,
            'total_cost' => (float) $this->total_cost,
            'total_charge' => (float) $this->total_charge,
            'status' => $this->status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'work_summary' => $this->work_summary,
        ];
    }
}
