<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Free slots of a day on the public booking portal.
 */
final class PublicAvailabilityRequest extends FormRequest
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
            'date' => ['required', 'date', 'after_or_equal:today'],
            'discipline' => ['required', Rule::in(Discipline::values())],
            'language' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'slot_minutes' => ['required', 'integer', Rule::in((array) config('ski_school.slot_minutes'))],
            'slot_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('ski_school.max_slots_per_lesson')],
        ];
    }
}
