<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

use Illuminate\Validation\Rule;
use Modules\Budgeting\Services\ReforecastService;

/**
 * A rolling forecast of an approved version: periods before from_period take the actuals, the others are re-forecast.
 */
final class ReforecastRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'from_period' => ['required', 'integer', 'min:1', 'max:12'],
            'mode' => ['sometimes', Rule::in(ReforecastService::Modes)],
            'adjustment_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
        ];
    }
}
