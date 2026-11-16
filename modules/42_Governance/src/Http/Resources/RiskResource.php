<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Governance\Models\GovRisk;
use Modules\Governance\Services\GovernanceMembers;

/**
 * A risk of the register with its scores, ratings and, on the single record, the controls that mitigate it.
 *
 * @mixin GovRisk
 */
final class RiskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'likelihood' => $this->likelihood,
            'impact' => $this->impact,
            'inherent_score' => $this->inherent_score,
            'inherent_rating' => GovRisk::ratingOf($this->inherent_score),
            'residual_likelihood' => $this->residual_likelihood,
            'residual_impact' => $this->residual_impact,
            'residual_score' => $this->residual_score,
            'residual_rating' => $this->residual_score === null ? null : GovRisk::ratingOf($this->residual_score),
            'treatment' => $this->treatment,
            'treatment_notes' => $this->treatment_notes,
            'owner_id' => $this->owner_id,
            'owner' => app(GovernanceMembers::class)->reference($this->owner_id),
            'status' => $this->status,
            'next_review_date' => $this->next_review_date?->toDateString(),
            'is_review_overdue' => $this->isReviewOverdue(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'controls' => $this->whenLoaded('controls', fn () => ControlResource::collection($this->controls)->resolve($request)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
