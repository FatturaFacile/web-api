<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Domain\Enums\PackageItemType;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * A tourist books a private lesson on the public portal.
 */
final class PublicBookLessonRequest extends FormRequest
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
            'discipline' => ['required', Rule::in(Discipline::values())],
            'language_code' => ['required', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'lesson_date' => ['required', 'date', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'slot_minutes' => ['required', 'integer', Rule::in((array) config('ski_school.slot_minutes'))],
            'slot_count' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('ski_school.max_slots_per_lesson')],
            'participants' => ['nullable', 'integer', 'min:1', 'max:30'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_email' => ['required', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'level_id' => ['nullable', 'uuid', $this->levelExists()],
            'requested_instructor_id' => ['nullable', 'uuid', $this->inTenant('ski_instructors')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array', 'max:10'],
            'items.*.item_type' => ['required', Rule::in(PackageItemType::values())],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
            'items.*.days' => ['nullable', 'integer', 'min:1', 'max:14'],
        ];
    }
}
