<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PerformanceReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'review_number' => $this->review_number,
            'employee_id' => $this->employee_id,
            'review_period' => $this->review_period,
            'review_type' => $this->review_type,
            'overall_rating' => (float) $this->overall_rating,
            'goals_score' => (float) $this->goals_score,
            'competencies_score' => (float) $this->competencies_score,
            'status' => $this->status,
            'promotion_recommended' => (bool) $this->promotion_recommended,
            'successor_readiness' => $this->successor_readiness,
            'is_locked' => (bool) $this->is_locked,
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
