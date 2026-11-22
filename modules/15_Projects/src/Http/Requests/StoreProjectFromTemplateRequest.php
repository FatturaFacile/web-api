<?php

declare(strict_types=1);

namespace Modules\Projects\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Projects\Models\Project;

/**
 * Opens a project from a project template (WBS, relative dates, dependencies and roles come from the template). The end
 * date defaults to the end of the template schedule.
 */
final class StoreProjectFromTemplateRequest extends FormRequest
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
            'template_id' => ['required', 'uuid', $this->tenantExists('project_templates')],
            'contact_id' => ['required', 'uuid', $this->tenantOwns('contacts')],
            'portfolio_id' => ['nullable', 'uuid', $this->tenantOwns('project_portfolios')],
            'cost_center_id' => ['nullable', 'uuid'],
            'project_number' => ['required', 'string', 'max:100'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_type' => ['required', 'string', Rule::in(StoreProjectRequest::ProjectTypes)],
            'billing_method' => ['required', 'string', Rule::in(StoreProjectRequest::BillingMethods)],
            'revenue_recognition_method' => ['nullable', 'string', Rule::in(Project::RecognitionMethods)],
            'currency' => ['nullable', 'string', 'size:3'],
            'planned_start_date' => ['required', 'date'],
            'planned_end_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'project_manager_user_id' => ['required', 'uuid', $this->tenantMember()],
            'contract_value' => ['nullable', 'numeric', 'gte:0', 'max:999999999999'],
            'cup_code' => ['nullable', 'string', 'regex:/^[A-Z0-9]{15}$/'],
            'cig_code' => ['nullable', 'string', 'regex:/^[A-Z0-9]{10}$/'],
            'sdi_reference_block' => ['nullable', 'string', Rule::in(['contract', 'convention'])],
        ];
    }
}
