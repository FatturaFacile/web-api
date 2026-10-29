<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'employee_number' => $this->employee_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'work_email' => $this->work_email,
            'department_id' => $this->department_id,
            'team_id' => $this->team_id,
            'business_unit_id' => $this->business_unit_id,
            'cost_center_id' => $this->cost_center_id,
            'position_id' => $this->position_id,
            'job_grade_id' => $this->job_grade_id,
            'manager_id' => $this->manager_id,
            'employment_status' => $this->employment_status,
            'employment_type' => $this->employment_type,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'probation_end_date' => $this->probation_end_date?->format('Y-m-d'),
            'termination_date' => $this->termination_date?->format('Y-m-d'),
            'base_annual_salary' => (float) $this->base_annual_salary,
            'hourly_cost_rate' => (float) $this->hourly_cost_rate,
            'currency' => $this->currency,
            'fte_ratio' => (float) $this->fte_ratio,
            'approval_status' => $this->approval_status,
            'is_locked' => (bool) $this->is_locked,
            'equipment_assigned' => $this->equipment_assigned ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
