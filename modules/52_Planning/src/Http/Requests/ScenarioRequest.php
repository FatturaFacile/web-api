<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Planning\Models\PlanningScenario;

/**
 * A planning scenario: the baseline or a what-if scenario with its monthly horizon.
 */
final class ScenarioRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->forMethod([
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'scenario_type' => ['required', Rule::in(PlanningScenario::Types)],
            'horizon_start' => ['required', 'date_format:Y-m-d'],
            'horizon_end' => ['required', 'date_format:Y-m-d'],
        ]);

        if ($this->isMethod('POST')) {
            $rules['horizon_end'][] = 'after_or_equal:horizon_start';
        }

        return $rules;
    }
}
