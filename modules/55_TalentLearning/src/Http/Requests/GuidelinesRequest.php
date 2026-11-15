<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * The full list of merit guidelines of a cycle (one row per performance rating).
 */
final class GuidelinesRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'guidelines' => ['required', 'array', 'max:5'],
            'guidelines.*.performance_rating' => ['required', 'integer', 'between:1,5'],
            'guidelines.*.min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'guidelines.*.max_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
