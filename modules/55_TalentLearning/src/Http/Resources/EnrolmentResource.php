<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TalentLearning\Models\LmsEnrolment;

/**
 * An enrolment of an employee in a course.
 *
 * @mixin LmsEnrolment
 */
final class EnrolmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_id' => $this->course_id,
            'course' => $this->whenLoaded('course', fn (): array => ['id' => $this->course->id, 'code' => $this->course->code, 'title' => $this->course->title, 'is_mandatory' => $this->course->is_mandatory, 'pass_score' => $this->course->pass_score]),
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee'),
            'learning_path_id' => $this->learning_path_id,
            'status' => $this->status,
            'progress_percent' => $this->progress_percent,
            'due_on' => $this->due_on?->toDateString(),
            'is_overdue' => in_array($this->status, [LmsEnrolment::Enrolled, LmsEnrolment::InProgress], true) && $this->due_on !== null && $this->due_on->isBefore(today()),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'score' => $this->score,
            'certificate' => $this->whenLoaded('certificate', fn (): ?array => $this->certificate === null ? null : ['id' => $this->certificate->id, 'certificate_number' => $this->certificate->certificate_number, 'expires_on' => $this->certificate->expires_on?->toDateString()]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
