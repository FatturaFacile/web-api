<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * A merit proposal. The cycle and the employee are fixed once it is created; the salary comes from human resources.
 */
final class MeritProposalRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'budget_pool_id' => ['required', 'uuid', $this->ownRow('tl_comp_budget_pools')],
            'performance_rating' => ['required', 'integer', 'between:1,5'],
            'increase_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'justification' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST')
            ? ['cycle_id' => ['required', 'uuid', $this->ownRow('tl_comp_cycles')], 'employee_id' => ['required', 'uuid'], ...$rules]
            : $rules;
    }
}
