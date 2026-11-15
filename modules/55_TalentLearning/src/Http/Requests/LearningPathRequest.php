<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * A learning path with its ordered courses.
 */
final class LearningPathRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string', 'max:5000'],
            ]),
            'course_ids' => [$this->isMethod('PUT') ? 'sometimes' : 'required', 'array', 'max:50'],
            'course_ids.*' => ['uuid'],
        ];
    }
}
