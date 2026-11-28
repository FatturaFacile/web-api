<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Move students between level groups (batch reassignment after the Monday evaluation).
 */
final class TransferStudentsRequest extends FormRequest
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
            'moves' => ['required', 'array', 'min:1', 'max:100'],
            'moves.*.enrollment_id' => ['required', 'uuid', $this->inTenant('group_course_enrollments')],
            'moves.*.to_class_id' => ['required', 'uuid', $this->inTenant('group_course_classes')],
            'reason' => ['nullable', 'string', 'max:200'],
        ];
    }
}
