<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectCostEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cost_category' => ['required', 'string', 'in:material,labour,supplier,fleet,expense,equipment'],
            'cost_source_type' => ['required', 'string'],
            'cost_source_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'gte:0'],
            'total_cost' => ['required', 'numeric', 'gte:0'],
            'incurred_date' => ['required', 'date'],
            'phase_id' => ['nullable', 'uuid', 'exists:project_phases,id'],
            'task_id' => ['nullable', 'uuid', 'exists:project_tasks,id'],
            'uom' => ['nullable', 'string', 'max:20'],
            'is_billable' => ['nullable', 'boolean'],
            'allocations' => ['nullable', 'array'],
        ];
    }
}
