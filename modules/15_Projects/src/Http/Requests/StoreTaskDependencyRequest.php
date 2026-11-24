<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\ProjectTaskDependency;
use OpenApi\Attributes as OA;

/**
 * A predecessor of the task in the path, with the type of link and the lag in calendar days (negative for a lead).
 */
#[OA\Schema(
    schema: 'ProjectsStoreTaskDependencyRequest',
    required: ['predecessor_task_id'],
    properties: [
        new OA\Property(property: 'predecessor_task_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'dependency_type', type: 'string', enum: ProjectTaskDependency::Types, default: 'finish_to_start'),
        new OA\Property(property: 'lag_days', type: 'integer', default: 0),
    ]
)]
final class StoreTaskDependencyRequest extends FormRequest
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
            'predecessor_task_id' => ['required', 'uuid', $this->tenantOwns('project_tasks')],
            'dependency_type' => ['nullable', 'string', Rule::in(ProjectTaskDependency::Types)],
            'lag_days' => ['nullable', 'integer', 'between:-365,365'],
        ];
    }
}
