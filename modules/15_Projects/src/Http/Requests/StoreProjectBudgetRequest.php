<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'total_revenue_budget' => ['required', 'numeric', 'gte:0'],
            'total_cost_budget' => ['required', 'numeric', 'gte:0'],
            'labour_budget' => ['nullable', 'numeric', 'gte:0'],
            'material_budget' => ['nullable', 'numeric', 'gte:0'],
            'supplier_budget' => ['nullable', 'numeric', 'gte:0'],
            'fleet_budget' => ['nullable', 'numeric', 'gte:0'],
            'expense_budget' => ['nullable', 'numeric', 'gte:0'],
            'contingency_budget' => ['nullable', 'numeric', 'gte:0'],
            'revision_reason' => ['nullable', 'string'],
            'lines' => ['nullable', 'array'],
            'lines.*.cost_category' => ['required_with:lines', 'string', 'in:labour,material,supplier,fleet,expense,contingency'],
            'lines.*.description' => ['required_with:lines', 'string'],
            'lines.*.planned_quantity' => ['required_with:lines', 'numeric', 'gt:0'],
            'lines.*.planned_unit_cost' => ['required_with:lines', 'numeric', 'gte:0'],
            'lines.*.planned_total_cost' => ['required_with:lines', 'numeric', 'gte:0'],
        ];
    }
}
