<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\EngagementSurvey;

/**
 * An engagement survey with its questions.
 *
 * @mixin EngagementSurvey
 */
final class SurveyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'is_anonymous' => $this->is_anonymous,
            'min_group_size' => $this->min_group_size,
            'opens_on' => $this->opens_on?->toDateString(),
            'closes_on' => $this->closes_on?->toDateString(),
            'has_answered' => $this->when(isset($this->resource->has_answered), fn (): bool => (bool) $this->resource->has_answered),
            'questions' => $this->whenLoaded('questions', fn (): array => $this->questions->map(static fn ($question): array => [
                'id' => $question->id,
                'position' => $question->position,
                'prompt' => $question->prompt,
                'question_type' => $question->question_type,
                'is_required' => $question->is_required,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
