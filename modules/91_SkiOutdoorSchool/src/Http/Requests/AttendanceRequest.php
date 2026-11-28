<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Daily attendance of a group course.
 */
final class AttendanceRequest extends FormRequest
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
            'attendance_date' => ['required', 'date'],
            'entries' => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.enrollment_id' => ['required', 'uuid', $this->inTenant('group_course_enrollments')],
            'entries.*.present' => ['required', 'boolean'],
        ];
    }
}
