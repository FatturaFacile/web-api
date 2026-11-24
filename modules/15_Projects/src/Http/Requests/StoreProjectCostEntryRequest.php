<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\ProjectCostEntry;
use OpenApi\Attributes as OA;

/**
 * A manual cost entry referencing its source document; a source document is booked once per project.
 */
#[OA\Schema(
    schema: 'ProjectsStoreCostEntryRequest',
    required: ['cost_category', 'cost_source_type', 'cost_source_id', 'quantity', 'unit_cost', 'incurred_date'],
    properties: [
        new OA\Property(property: 'cost_category', type: 'string', enum: ProjectCostEntry::Categories),
        new OA\Property(property: 'cost_source_type', type: 'string', maxLength: 100),
        new OA\Property(property: 'cost_source_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'quantity', type: 'number'),
        new OA\Property(property: 'unit_cost', type: 'number'),
        new OA\Property(property: 'total_cost', type: 'number', description: 'Defaults to quantity times unit cost, rounded to cents.'),
        new OA\Property(property: 'incurred_date', type: 'string', format: 'date'),
        new OA\Property(property: 'phase_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'task_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'uom', type: 'string', maxLength: 20),
        new OA\Property(property: 'is_billable', type: 'boolean'),
        new OA\Property(property: 'allocations', type: 'array', nullable: true, items: new OA\Items(properties: [new OA\Property(property: 'task_id', type: 'string', format: 'uuid'), new OA\Property(property: 'percentage', type: 'number')]), description: 'Split over tasks; percentages must add up to 100.'),
    ]
)]
final class StoreProjectCostEntryRequest extends FormRequest
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
            'cost_category' => ['required', 'string', Rule::in(ProjectCostEntry::Categories)],
            'cost_source_type' => ['required', 'string', 'max:100', Rule::notIn(['Timesheet', 'project_expense'])],
            'cost_source_id' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'unit_cost' => ['required', 'numeric', 'gte:0', 'max:9999999999'],
            'total_cost' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'incurred_date' => ['required', 'date'],
            'phase_id' => ['nullable', 'uuid', $this->tenantOwns('project_phases')],
            'task_id' => ['nullable', 'uuid', $this->tenantOwns('project_tasks')],
            'uom' => ['nullable', 'string', 'max:20'],
            'is_billable' => ['nullable', 'boolean'],
            'allocations' => ['nullable', 'array', 'max:50'],
            'allocations.*.task_id' => ['required', 'uuid', $this->tenantOwns('project_tasks')],
            'allocations.*.percentage' => ['required', 'numeric', 'gt:0', 'lte:100'],
        ];
    }
}
