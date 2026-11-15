<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetDriver;

/**
 * A planning driver: quantity times rate, spread over start_period to end_period by the chosen rule (percentages needs
 * one value per period of the range).
 */
final class DriverRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->forMethod([
            'name' => ['required', 'string', 'max:160'],
            'account_id' => ['required', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'rate' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'spread_method' => ['required', Rule::in(BudgetDriver::SpreadMethods)],
            'percentages' => ['nullable', 'array', 'max:12'],
            'percentages.*' => ['required', 'numeric', 'min:0', 'max:100'],
            'start_period' => ['required', 'integer', 'min:1', 'max:12'],
            'end_period' => ['required', 'integer', 'min:1', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
