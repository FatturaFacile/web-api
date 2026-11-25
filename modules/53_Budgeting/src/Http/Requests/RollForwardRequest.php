<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * The roll forward of a rolling forecast: how many months the window moves and an optional adjustment of the months ahead.
 */
final class RollForwardRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'shift_months' => ['nullable', 'integer', 'min:1', 'max:12'],
            'adjustment_percent' => ['nullable', 'numeric', 'min:-100', 'max:1000'],
        ];
    }
}
