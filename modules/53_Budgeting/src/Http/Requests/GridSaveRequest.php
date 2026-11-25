<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Requests;

/**
 * Cells of the budget grid: one amount per account, cost centre, project and slot (month, or quarter for a quarterly
 * version), optionally with the quantity and the unit price that explain it.
 */
final class GridSaveRequest extends BudgetingFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cells' => ['required', 'array', 'min:1', 'max:1000'],
            'cells.*.account_id' => ['required', 'uuid'],
            'cells.*.cost_center_id' => ['nullable', 'uuid'],
            'cells.*.project_id' => ['nullable', 'uuid'],
            'cells.*.slot' => ['required', 'integer', 'min:1', 'max:12'],
            'cells.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'cells.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'cells.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'cells.*.notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
