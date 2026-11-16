<?php

declare(strict_types=1);

namespace Modules\Sustainability\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sustainability\Models\EsgMaterialityAssessment;
use Modules\Sustainability\Models\EsgMaterialityTopic;

/**
 * A materiality assessment with its topics. A topic is material by impact, by financial effect or by both.
 *
 * @mixin EsgMaterialityAssessment
 */
final class MaterialityAssessmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $topics = $this->relationLoaded('topics') ? $this->topics : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'reporting_period_id' => $this->reporting_period_id,
            'threshold' => $this->threshold,
            'status' => $this->status,
            'notes' => $this->notes,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'topics' => $topics?->map(fn (EsgMaterialityTopic $topic): array => [
                'id' => $topic->id,
                'esrs_code' => $topic->esrs_code,
                'name' => $topic->name,
                'impact_score' => $topic->impact_score,
                'financial_score' => $topic->financial_score,
                'is_material' => $topic->is_material,
                'materiality_type' => $this->typeOf($topic),
                'rationale' => $topic->rationale,
            ])->values()->all(),
            'material_topics_count' => $topics?->where('is_material', true)->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function typeOf(EsgMaterialityTopic $topic): string
    {
        $impact = bccomp((string) $topic->impact_score, (string) $this->threshold, 2) >= 0;
        $financial = bccomp((string) $topic->financial_score, (string) $this->threshold, 2) >= 0;

        return match (true) {
            $impact && $financial => 'both',
            $impact => 'impact',
            $financial => 'financial',
            default => 'none',
        };
    }
}
