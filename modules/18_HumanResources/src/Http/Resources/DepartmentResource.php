<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'parent_id' => $this->parent_id,
            'business_unit_id' => $this->business_unit_id,
            'cost_center_id' => $this->cost_center_id,
            'manager_employee_id' => $this->manager_employee_id,
            'is_active' => (bool) $this->is_active,
            'translations' => $this->translations ?? [],
        ];
    }
}
