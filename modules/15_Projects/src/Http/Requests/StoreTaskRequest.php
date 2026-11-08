<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\ProjectTask;
use OpenApi\Attributes as OA;

/**
 * A work package of the WBS. Phase, milestone and parent must belong to the same project (checked by the service).
 */
#[OA\Schema(
    schema: 'ProjectsStoreTaskRequest',
    required: ['task_number', 'title', 'planned_start_date', 'planned_end_date'],
    properties: [
        new OA\Property(property: 'task_number', type: 'string', maxLength: 100),
        new OA\Property(property: 'wbs_code', type: 'string', maxLength: 50, nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer', nullable: true),
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'project_phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_milestone_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ProjectTask::Statuses),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'estimated_hours', type: 'number', minimum: 0),
        new OA\Property(property: 'is_milestone_driver', type: 'boolean'),
    ]
)]
final class StoreTaskRequest extends FormRequest
{
    use TenantScopedRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'task_number' => ['required', 'string', 'max:100'],
            'wbs_code' => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_phase_id' => ['nullable', 'uuid', $this->tenantOwns('project_phases')],
            'project_milestone_id' => ['nullable', 'uuid', $this->tenantOwns('project_milestones')],
            'parent_task_id' => ['nullable', 'uuid', $this->tenantOwns('project_tasks')],
            'status' => ['nullable', 'string', Rule::in(ProjectTask::Statuses)],
            'priority' => ['nullable', 'string', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'estimated_hours' => ['nullable', 'numeric', 'gte:0', 'max:999999'],
            'is_milestone_driver' => ['nullable', 'boolean'],
        ];
    }
}
