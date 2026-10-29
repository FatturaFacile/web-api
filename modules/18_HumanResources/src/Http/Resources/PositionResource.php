<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'department_id' => $this->department_id,
            'job_grade_id' => $this->job_grade_id,
            'headcount_budget' => (int) $this->headcount_budget,
            'is_critical_role' => (bool) $this->is_critical_role,
            'successor_employee_id' => $this->successor_employee_id,
            'readiness_level' => $this->readiness_level,
            'is_active' => (bool) $this->is_active,
        ];
    }
}
