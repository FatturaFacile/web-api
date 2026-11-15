<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\TalentLearning\Models\SuccessionPosition;

/**
 * A critical position. Either the HR position or a title is given; the existence of HR ids is checked by the service
 * through the HR directory.
 */
final class SuccessionPositionRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'position_id' => ['nullable', 'uuid'],
            'title' => ['nullable', 'string', 'max:150'],
            'incumbent_employee_id' => ['nullable', 'uuid'],
            'criticality' => ['string', Rule::in(SuccessionPosition::Criticality)],
            'vacancy_risk' => ['string', Rule::in(SuccessionPosition::VacancyRisks)],
            'status' => ['string', Rule::in([SuccessionPosition::Active, SuccessionPosition::Archived])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
