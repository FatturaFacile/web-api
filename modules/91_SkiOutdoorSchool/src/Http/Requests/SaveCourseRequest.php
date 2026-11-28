<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\CourseStatus;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\VatRegime;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Create or change a weekly group course.
 */
final class SaveCourseRequest extends FormRequest
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
            'code' => ['nullable', 'string', 'max:40'],
            'discipline' => ['required', Rule::in(Discipline::values())],
            'level_id' => ['required', 'uuid', $this->levelExists()],
            'instructor_id' => ['nullable', 'uuid', $this->inTenant('ski_instructors')],
            'language_code' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'week_start' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:week_start'],
            'daily_start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:480'],
            'capacity' => ['required', 'integer', 'min:1', 'max:60'],
            'min_participants' => ['nullable', 'integer', 'min:1', 'max:60'],
            'meeting_point' => ['nullable', 'string', 'max:160'],
            'price_net' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'vat_regime' => ['nullable', Rule::in(VatRegime::values())],
            'status' => ['nullable', Rule::in(CourseStatus::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
