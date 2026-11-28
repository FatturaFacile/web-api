<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Filters of the planning board.
 */
final class BoardRequest extends FormRequest
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
            'date' => ['nullable', 'date'],
            'discipline' => ['nullable', Rule::in(Discipline::values())],
            'language' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'instructor_id' => ['nullable', 'uuid', $this->inTenant('ski_instructors')],
            'slot_minutes' => ['nullable', 'integer', Rule::in((array) config('ski_school.slot_minutes'))],
            'slot_start' => ['nullable', 'date_format:H:i'],
            'only_available' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:80'],
        ];
    }
}
