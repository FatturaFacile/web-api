<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Models\BudgetDriver;

/**
 * An amount spread over a range of periods of one account (and cost centre and project): evenly, by the seasonal weights
 * of the version, or by percentages (one per period of the range, adding up to 100).
 */
final class SpreadRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'uuid'],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'],
            'total_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'method' => ['required', Rule::in(BudgetDriver::SpreadMethods)],
            'start_period' => ['required', 'integer', 'min:1', 'max:12'],
            'end_period' => ['required', 'integer', 'min:1', 'max:12', 'gte:start_period'],
            'percentages' => ['nullable', 'array', 'max:12'],
            'percentages.*' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
