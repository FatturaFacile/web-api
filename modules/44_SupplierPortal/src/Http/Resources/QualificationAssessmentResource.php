<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SupplierPortal\Models\QualificationAssessment;
use Modules\SupplierPortal\Models\QualificationScore;

/**
 * A qualification assessment with its scores.
 *
 * @mixin QualificationAssessment
 */
final class QualificationAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'onboarding_id' => $this->onboarding_id,
            'status' => $this->status,
            'total_score' => $this->total_score,
            'band' => $this->band,
            'outcome' => $this->outcome,
            'assessed_by' => $this->assessed_by,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,
            'scores' => $this->whenLoaded('scores', fn () => $this->scores->map(static fn (QualificationScore $score): array => [
                'criterion_id' => $score->criterion_id,
                'criterion_code' => $score->criterion?->code,
                'criterion_name' => $score->criterion?->name,
                'weight' => $score->criterion?->weight,
                'max_score' => $score->criterion?->max_score,
                'score' => $score->score,
                'notes' => $score->notes,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
