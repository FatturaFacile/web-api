<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Requests\Recruiting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\Recommendation;

final class SubmitInterviewScorecardRequest extends FormRequest
{
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
            'stage' => ['required', 'string', Rule::in(ApplicationStage::interviewStageValues())],
            'criteria_scores' => ['required', 'array', 'min:1', 'max:30'],
            'criteria_scores.*.criterion' => ['required', 'string', 'max:120'],
            'criteria_scores.*.score' => ['required', 'integer', 'between:1,5'],
            'criteria_scores.*.comment' => ['nullable', 'string', 'max:2000'],
            'recommendation' => ['required', 'string', Rule::in(Recommendation::values())],
            'notes' => ['nullable', 'string', 'max:20000'],
            'interviewed_at' => ['nullable', 'date'],
        ];
    }
}
