<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Requests;

/**
 * A budget pool of a cycle. The cycle is fixed once the pool is created.
 */
final class BudgetPoolRequest extends TalentFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:150'],
            'department_id' => ['nullable', 'uuid'],
            'budget_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->isMethod('POST') ? ['cycle_id' => ['required', 'uuid', $this->ownRow('tl_comp_cycles')], ...$rules] : $rules;
    }
}
