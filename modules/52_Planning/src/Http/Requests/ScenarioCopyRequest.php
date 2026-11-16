<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

/**
 * A what-if copy of a scenario, optionally scaling the demand and the supply by a percentage.
 */
final class ScenarioCopyRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'demand_adjustment_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
            'supply_adjustment_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
        ];
    }
}
