<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Move an open lesson to another day, time or instructor.
 */
final class RescheduleLessonRequest extends FormRequest
{
    use SkiSchoolRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lesson_date' => ['nullable', 'date', 'after_or_equal:today'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'slot_minutes' => ['nullable', 'integer', Rule::in((array) config('ski_school.slot_minutes'))],
            'slot_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('ski_school.max_slots_per_lesson')],
            'instructor_id' => ['nullable', 'uuid', $this->inTenant('ski_instructors')],
        ];
    }
}
