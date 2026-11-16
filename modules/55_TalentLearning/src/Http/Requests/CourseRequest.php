<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\TalentLearning\Models\LmsCourse;

/**
 * A course of the catalogue. The status moves through the publish and archive actions.
 */
final class CourseRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'code' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'delivery' => ['required', Rule::in(LmsCourse::Deliveries)],
            'duration_hours' => ['required', 'numeric', 'min:0', 'max:9999'],
            'is_mandatory' => ['boolean'],
            'validity_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'pass_score' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
