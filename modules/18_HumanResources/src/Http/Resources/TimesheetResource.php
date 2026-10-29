<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TimesheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'timesheet_number' => $this->timesheet_number,
            'employee_id' => $this->employee_id,
            'project_id' => $this->project_id,
            'work_date' => $this->work_date?->format('Y-m-d'),
            'regular_hours' => (float) $this->regular_hours,
            'overtime_hours' => (float) $this->overtime_hours,
            'total_hours' => (float) $this->total_hours,
            'billable_hours' => (float) $this->billable_hours,
            'hourly_cost_rate' => (float) $this->hourly_cost_rate,
            'total_labor_cost' => (float) $this->total_labor_cost,
            'status' => $this->status,
        ];
    }
}
