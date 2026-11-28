<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\SkiOutdoorSchool\Domain\Enums\Discipline;
use Modules\SkiOutdoorSchool\Http\Requests\Concerns\SkiSchoolRules;

/**
 * Filters of the weekly courses offered on the public portal.
 */
final class PublicCourseOfferRequest extends FormRequest
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
            'discipline' => ['nullable', Rule::in(Discipline::values())],
            'language' => ['nullable', 'string', 'regex:/^[A-Za-z]{2,3}$/'],
            'level_id' => ['nullable', 'uuid', $this->levelExists()],
            'date_from' => ['nullable', 'date'],
        ];
    }
}
