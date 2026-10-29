<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'employee_id' => $this->employee_id,
            'leave_type_id' => $this->leave_type_id,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'total_hours' => (float) $this->total_hours,
            'total_days' => (float) $this->total_days,
            'status' => $this->status,
            'is_locked' => (bool) $this->is_locked,
            'reason' => $this->reason,
            'approved_at' => $this->approved_at?->toIso8601String(),
        ];
    }
}
