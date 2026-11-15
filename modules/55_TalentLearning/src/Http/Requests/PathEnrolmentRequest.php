<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * Enrols employees in every course of a learning path.
 */
final class PathEnrolmentRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:200'],
            'employee_ids.*' => ['uuid', 'distinct'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
