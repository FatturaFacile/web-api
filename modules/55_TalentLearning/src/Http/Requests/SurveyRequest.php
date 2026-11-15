<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\TalentLearning\Models\EngagementQuestion;

/**
 * An engagement survey with its questions. Only a draft survey can be changed.
 */
final class SurveyRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->forMethod([
                'title' => ['required', 'string', 'max:200'],
                'description' => ['nullable', 'string', 'max:5000'],
                'is_anonymous' => ['boolean'],
                'min_group_size' => ['integer', 'min:3', 'max:50'],
                'opens_on' => ['nullable', 'date_format:Y-m-d'],
                'closes_on' => array_filter(['nullable', 'date_format:Y-m-d', $this->filled('opens_on') ? 'after_or_equal:opens_on' : null]),
            ]),
            'questions' => [$this->isMethod('PUT') ? 'sometimes' : 'required', 'array', 'min:1', 'max:60'],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.question_type' => ['required', Rule::in([EngagementQuestion::Rating, EngagementQuestion::Enps, EngagementQuestion::Text])],
            'questions.*.is_required' => ['boolean'],
        ];
    }
}
