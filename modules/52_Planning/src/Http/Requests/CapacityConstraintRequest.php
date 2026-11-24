<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

/**
 * The capacity of a resource in a month of a scenario (one constraint per resource and month: saving again replaces it).
 */
final class CapacityConstraintRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_id' => ['required', 'uuid', $this->ownRow('planning_resources')],
            'period' => ['required', 'date_format:Y-m-d'],
            'available_capacity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
