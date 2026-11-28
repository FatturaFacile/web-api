<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Slot for which the fair rotation ranks the instructors.
 */
final class RotationRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'slot_minutes' => ['required', 'integer', Rule::in((array) config('ski_school.slot_minutes'))],
            'slot_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('ski_school.max_slots_per_lesson')],
            'discipline' => ['required', Rule::in(Discipline::values())],
            'language' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'requested_instructor_id' => ['nullable', 'uuid', $this->inTenant('ski_instructors')],
            'period' => ['nullable', Rule::in(['week', 'month'])],
            'ignore_lesson_id' => ['nullable', 'uuid', $this->inTenant('ski_lessons')],
        ];
    }
}
