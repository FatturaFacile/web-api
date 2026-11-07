<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;

final class StoreTimesheetRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'uuid', $this->tenantMember()],
            'work_date' => ['required', 'date'],
            'hours_worked' => ['required', 'numeric', 'gt:0'],
            'task_id' => ['nullable', 'uuid', $this->tenantExists('project_tasks')],
            'overtime_hours' => ['nullable', 'numeric', 'gte:0'],
            'is_billable' => ['nullable', 'boolean'],
            'custom_cost_rate' => ['nullable', 'numeric', 'gte:0'],
            'custom_charge_rate' => ['nullable', 'numeric', 'gte:0'],
            'work_summary' => ['nullable', 'string'],
        ];
    }
}
