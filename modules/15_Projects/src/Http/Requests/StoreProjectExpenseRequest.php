<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category' => ['required', 'string', 'in:travel_lodging,meals_subsistence,licensing_permits,site_rentals,laboratory_testing,expense,subcontractor,equipment'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'incurred_date' => ['required', 'date'],
            'phase_id' => ['nullable', 'uuid', 'exists:project_phases,id'],
            'task_id' => ['nullable', 'uuid', 'exists:project_tasks,id'],
            'receipt_document_id' => ['nullable', 'uuid'],
            'is_billable' => ['nullable', 'boolean'],
        ];
    }
}
