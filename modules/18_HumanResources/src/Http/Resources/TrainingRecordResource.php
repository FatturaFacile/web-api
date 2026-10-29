<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TrainingRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_number' => $this->enrollment_number,
            'employee_id' => $this->employee_id,
            'course_id' => $this->course_id,
            'status' => $this->status,
            'assigned_date' => $this->assigned_date?->format('Y-m-d'),
            'completed_date' => $this->completed_date?->format('Y-m-d'),
            'score' => $this->score !== null ? (float) $this->score : null,
            'cost_amount' => (float) $this->cost_amount,
            'certificate_issued' => (bool) $this->certificate_issued,
        ];
    }
}
