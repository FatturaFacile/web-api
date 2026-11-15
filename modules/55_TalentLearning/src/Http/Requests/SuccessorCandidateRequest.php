<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\TalentLearning\Models\SuccessionCandidate;

/**
 * A successor candidate. The employee is fixed once the candidate is created.
 */
final class SuccessorCandidateRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'readiness' => ['string', Rule::in(SuccessionCandidate::Readiness)],
            'risk_of_loss' => ['string', Rule::in(SuccessionCandidate::Levels)],
            'potential' => ['string', Rule::in(SuccessionCandidate::Levels)],
            'ranking' => ['integer', 'min:1', 'max:999'],
            'development_plan' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? ['employee_id' => ['required', 'uuid'], ...$rules] : $rules;
    }
}
