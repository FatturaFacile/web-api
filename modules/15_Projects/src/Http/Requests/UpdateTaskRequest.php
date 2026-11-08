<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\ProjectTask;
use OpenApi\Attributes as OA;

/**
 * Changes to a task: plan dates, status, progress and actual dates. Completing a task sets its progress to 100%.
 */
#[OA\Schema(
    schema: 'ProjectsUpdateTaskRequest',
    properties: [
        new OA\Property(property: 'title', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'wbs_code', type: 'string', nullable: true),
        new OA\Property(property: 'sort_order', type: 'integer'),
        new OA\Property(property: 'project_phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_milestone_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ProjectTask::Statuses),
        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high', 'urgent']),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'actual_start_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'actual_end_date', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'estimated_hours', type: 'number', minimum: 0),
        new OA\Property(property: 'progress_percentage', type: 'number', minimum: 0, maximum: 100),
    ]
)]
final class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'wbs_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'project_phase_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('project_phases')],
            'project_milestone_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('project_milestones')],
            'parent_task_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('project_tasks')],
            'status' => ['sometimes', 'string', Rule::in(ProjectTask::Statuses)],
            'priority' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'planned_start_date' => ['sometimes', 'date'],
            'planned_end_date' => ['sometimes', 'date'],
            'actual_start_date' => ['sometimes', 'nullable', 'date'],
            'actual_end_date' => ['sometimes', 'nullable', 'date'],
            'estimated_hours' => ['sometimes', 'numeric', 'gte:0', 'max:999999'],
            'progress_percentage' => ['sometimes', 'numeric', 'between:0,100'],
        ];
    }
}
