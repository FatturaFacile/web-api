<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\InterviewScorecard;

/**
 * @mixin InterviewScorecard
 */
final class InterviewScorecardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var InterviewScorecard $scorecard */
        $scorecard = $this->resource;

        return [
            'id' => (string) $scorecard->id,
            'application_id' => (string) $scorecard->application_id,
            'interviewer_user_id' => (string) $scorecard->interviewer_user_id,
            'interviewer_name' => $scorecard->interviewer_name,
            'stage' => (string) $scorecard->stage,
            'criteria_scores' => array_values(array_map(static fn (array $criterion): array => [
                'criterion' => (string) ($criterion['criterion'] ?? ''),
                'score' => (int) ($criterion['score'] ?? 0),
                'comment' => $criterion['comment'] ?? null,
            ], array_filter((array) $scorecard->criteria_scores, 'is_array'))),
            'overall_score' => round((float) $scorecard->overall_score, 2),
            'recommendation' => (string) $scorecard->recommendation,
            'notes' => $scorecard->notes,
            'interviewed_at' => $scorecard->interviewed_at?->toIso8601ZuluString(),
            'created_at' => $scorecard->created_at?->toIso8601ZuluString(),
            'updated_at' => $scorecard->updated_at?->toIso8601ZuluString(),
        ];
    }
}
