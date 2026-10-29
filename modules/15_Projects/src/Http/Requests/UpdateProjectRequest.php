<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:draft,planned,approved,active,on_hold,completed,closed,cancelled'],
            'planned_start_date' => ['sometimes', 'date'],
            'planned_end_date' => ['sometimes', 'date'],
            'actual_start_date' => ['nullable', 'date'],
            'actual_end_date' => ['nullable', 'date'],
            'contract_value' => ['sometimes', 'numeric', 'gte:0'],
            'progress_percentage' => ['sometimes', 'numeric', 'between:0,100'],
            'health_status' => ['sometimes', 'string', 'in:green,yellow,red'],
            'project_manager_user_id' => ['sometimes', 'uuid', 'exists:users,id'],
        ];
    }
}
