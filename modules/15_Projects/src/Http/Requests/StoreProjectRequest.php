<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Project;
use OpenApi\Attributes as OA;

/**
 * Opening a project: customer, dates, project manager, contract value and how its revenue is recognised.
 */
#[OA\Schema(
    schema: 'ProjectsStoreProjectRequest',
    required: ['contact_id', 'project_number', 'name', 'project_type', 'billing_method', 'planned_start_date', 'planned_end_date', 'project_manager_user_id', 'contract_value'],
    properties: [
        new OA\Property(property: 'contact_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'portfolio_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'cost_center_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'parent_project_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'project_number', type: 'string', maxLength: 100),
        new OA\Property(property: 'name', type: 'string', maxLength: 255),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'project_type', type: 'string', enum: StoreProjectRequest::ProjectTypes),
        new OA\Property(property: 'billing_method', type: 'string', enum: StoreProjectRequest::BillingMethods),
        new OA\Property(property: 'revenue_recognition_method', type: 'string', enum: Project::RecognitionMethods, nullable: true, description: 'Leave empty when revenue is the invoiced revenue (time and materials, internal projects).'),
        new OA\Property(property: 'currency', type: 'string', maxLength: 3, example: 'EUR'),
        new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'),
        new OA\Property(property: 'planned_end_date', type: 'string', format: 'date'),
        new OA\Property(property: 'project_manager_user_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'contract_value', type: 'number', minimum: 0),
        new OA\Property(property: 'phases', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'sequence_order', type: 'integer'), new OA\Property(property: 'planned_start_date', type: 'string', format: 'date'), new OA\Property(property: 'planned_end_date', type: 'string', format: 'date')]), nullable: true),
        new OA\Property(property: 'milestones', type: 'array', items: new OA\Items(properties: [new OA\Property(property: 'name', type: 'string'), new OA\Property(property: 'milestone_date', type: 'string', format: 'date'), new OA\Property(property: 'billing_amount', type: 'number'), new OA\Property(property: 'billing_percentage', type: 'number')]), nullable: true),
    ]
)]
final class StoreProjectRequest extends FormRequest
{
    use TenantScopedRules;

    public const array ProjectTypes = ['fixed_price', 'time_and_material', 'time_and_materials', 'milestone', 'internal_rd', 'construction', 'consulting', 'software_dev', 'internal', 'customer', 'maintenance', 'capex'];

    public const array BillingMethods = ['fixed_price', 'time_and_materials', 'time_material', 'cost_plus', 'internal', 'milestone_based', 'percentage_of_completion', 'milestone'];

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
            'contact_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
            'portfolio_id' => ['nullable', 'uuid', $this->tenantOwns('project_portfolios')],
            'cost_center_id' => ['nullable', 'uuid'],
            'parent_project_id' => ['nullable', 'uuid', $this->tenantOwns('projects')],
            'project_number' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_type' => ['required', 'string', Rule::in(self::ProjectTypes)],
            'billing_method' => ['required', 'string', Rule::in(self::BillingMethods)],
            'revenue_recognition_method' => ['nullable', 'string', Rule::in(Project::RecognitionMethods)],
            'currency' => ['nullable', 'string', 'size:3'],
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['required', 'date', 'after_or_equal:planned_start_date'],
            'project_manager_user_id' => ['required', 'uuid', $this->tenantMember()],
            'contract_value' => ['required', 'numeric', 'gte:0', 'max:999999999999'],
            'phases' => ['nullable', 'array', 'max:100'],
            'phases.*.name' => ['required', 'string', 'max:150'],
            'phases.*.sequence_order' => ['nullable', 'integer', 'min:1'],
            'phases.*.planned_start_date' => ['nullable', 'date'],
            'phases.*.planned_end_date' => ['nullable', 'date'],
            'milestones' => ['nullable', 'array', 'max:200'],
            'milestones.*.name' => ['required', 'string', 'max:150'],
            'milestones.*.milestone_date' => ['required', 'date'],
            'milestones.*.billing_amount' => ['nullable', 'numeric', 'gte:0'],
            'milestones.*.billing_percentage' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }
}
