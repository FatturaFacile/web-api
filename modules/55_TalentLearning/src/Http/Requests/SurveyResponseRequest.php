<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * The answers of the employee to an open survey. The ranges of the values depend on the type of the question and are
 * checked by the service.
 */
final class SurveyResponseRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:60'],
            'answers.*.question_id' => ['required', 'uuid'],
            'answers.*.value' => ['nullable', 'integer'],
            'answers.*.text' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
