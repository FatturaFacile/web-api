<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\LmsLearningPath;

/**
 * A learning path with its ordered courses.
 *
 * @mixin LmsLearningPath
 */
final class LearningPathResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'courses' => $this->whenLoaded('steps', fn (): array => $this->steps->map(static fn ($step): array => [
                'position' => $step->position,
                'course' => $step->course === null ? null : ['id' => $step->course->id, 'code' => $step->course->code, 'title' => $step->course->title, 'duration_hours' => $step->course->duration_hours, 'status' => $step->course->status],
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
