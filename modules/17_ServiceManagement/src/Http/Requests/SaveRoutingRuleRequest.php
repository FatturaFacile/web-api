<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\ServiceManagement\Services\Helpdesk\TicketAssignmentService;

final class SaveRoutingRuleRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['sometimes', 'boolean'],
            'stop_processing' => ['sometimes', 'boolean'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'conditions.category_ids' => ['sometimes', 'array'],
            'conditions.category_ids.*' => ['uuid', $this->tenantExists('esm_service_categories')],
            'conditions.sources' => ['sometimes', 'array'],
            'conditions.sources.*' => ['string', 'in:portal,email,phone,chat,api,field,monitoring'],
            'conditions.customer_ids' => ['sometimes', 'array'],
            'conditions.customer_ids.*' => ['uuid', $this->tenantOwns('contacts')],
            'conditions.keywords' => ['sometimes', 'array', 'max:50'],
            'conditions.keywords.*' => ['string', 'max:100'],
            'conditions.priorities' => ['sometimes', 'array'],
            'conditions.priorities.*' => ['string', 'in:low,medium,high,critical'],
            'actions' => [$required, 'array'],
            'actions.assign_team_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'actions.assign_technician_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
            'actions.assignment_strategy' => ['sometimes', 'nullable', 'string', Rule::in(TicketAssignmentService::strategies())],
            'actions.set_priority' => ['sometimes', 'nullable', 'string', 'in:low,medium,high,critical'],
            'actions.add_tags' => ['sometimes', 'array', 'max:20'],
            'actions.add_tags.*' => ['string', 'max:50'],
        ];
    }
}
