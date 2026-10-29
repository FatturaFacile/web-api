<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LeaveBalanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'leave_type_id' => $this->leave_type_id,
            'fiscal_year' => (int) $this->fiscal_year,
            'entitlement_hours' => (float) $this->entitlement_hours,
            'carryover_hours' => (float) $this->carryover_hours,
            'accrued_hours' => (float) $this->accrued_hours,
            'used_hours' => (float) $this->used_hours,
            'pending_hours' => (float) $this->pending_hours,
            'available_hours' => (float) $this->available_hours,
        ];
    }
}
