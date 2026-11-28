<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SkiOutdoorSchool\Models\GroupCourse;

/**
 * @mixin GroupCourse
 */
final class CourseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discipline' => $this->discipline->value,
            'level_id' => $this->level_id,
            'level' => $this->relationLoaded('level') && $this->level !== null ? new LevelResource($this->level) : null,
            'instructor_id' => $this->instructor_id,
            'instructor_name' => $this->relationLoaded('instructor') ? $this->instructor?->fullName() : null,
            'language_code' => $this->language_code,
            'week_start' => $this->week_start->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'daily_start_time' => $this->daily_start_time,
            'duration_minutes' => $this->duration_minutes,
            'capacity' => $this->capacity,
            'min_participants' => $this->min_participants,
            'enrolled' => (int) ($this->enrolled_count ?? $this->activeEnrollmentCount()),
            'meeting_point' => $this->meeting_point,
            'price_net' => $this->price_net,
            'vat_regime' => $this->vat_regime->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'enrollments' => $this->relationLoaded('enrollments') ? EnrollmentResource::collection($this->enrollments) : [],
        ];
    }
}
