<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'uuid', 'exists:contacts,id'],
            'portfolio_id' => ['nullable', 'uuid', 'exists:project_portfolios,id'],
            'cost_center_id' => ['nullable', 'uuid'],
            'parent_project_id' => ['nullable', 'uuid', 'exists:projects,id'],
            'project_number' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'project_type' => ['required', 'string', 'in:fixed_price,time_and_material,time_and_materials,milestone,internal_rd,construction,consulting,software_dev,internal,customer,maintenance,capex'],
            'billing_method' => ['required', 'string', 'in:fixed_price,time_and_materials,time_material,cost_plus,internal,milestone_based,percentage_of_completion,milestone'],
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'project_manager_user_id' => ['required', 'uuid', 'exists:users,id'],
            'contract_value' => ['required', 'numeric', 'gte:0'],
            'phases' => ['nullable', 'array'],
            'milestones' => ['nullable', 'array'],
        ];
    }
}
