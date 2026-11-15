<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * An enrolment of an employee in a course.
 */
final class EnrolmentRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'course_id' => ['required', 'uuid', $this->ownRow('tl_courses')],
            'employee_id' => ['required', 'uuid'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
