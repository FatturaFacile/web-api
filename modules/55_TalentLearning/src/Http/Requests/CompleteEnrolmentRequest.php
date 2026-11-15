<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * Completion of an enrolment: the score is required when the course has a pass score.
 */
final class CompleteEnrolmentRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['score' => ['nullable', 'numeric', 'min:0', 'max:100']];
    }
}
