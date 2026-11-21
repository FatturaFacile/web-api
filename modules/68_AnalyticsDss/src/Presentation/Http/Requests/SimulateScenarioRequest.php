<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Presentation\Http\Requests;

/**
 * Filter and sensitivity sliders of the what-if simulation (percentages are whole numbers).
 */
final class SimulateScenarioRequest extends DssFilterRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'scenario' => ['required', 'array'],
            'scenario.price_pct' => ['nullable', 'numeric', 'between:-100,500'],
            'scenario.cogs_pct' => ['nullable', 'numeric', 'between:-100,500'],
            'scenario.opex_pct' => ['nullable', 'numeric', 'between:-100,500'],
            'scenario.dso_days_delta' => ['nullable', 'numeric', 'between:-365,365'],
            'scenario.fx_shift_pct' => ['nullable', 'numeric', 'between:-50,50'],
            'scenario.fte_delta' => ['nullable', 'numeric', 'between:-1000,1000'],
        ];
    }
}
