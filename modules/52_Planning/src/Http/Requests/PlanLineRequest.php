<?php

declare(strict_types=1);

namespace Modules\Planning\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Planning\Models\PlanningPlanLine;

/**
 * A demand or supply line of a scenario. The item is an item of the item master (item_id) or a free code (item_code);
 * the month is any date of it. Saving the same side, item and month again replaces the quantity.
 */
final class PlanLineRequest extends PlanningFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'line_type' => ['required', Rule::in(PlanningPlanLine::Types)],
            'item_id' => ['nullable', 'uuid'],
            'item_code' => ['nullable', 'string', 'max:60'],
            'item_name' => ['nullable', 'string', 'max:160'],
            'period' => ['required', 'date_format:Y-m-d'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
