<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Requests;

use App\Http\Requests\Concerns\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Modules\ServiceManagement\Services\Helpdesk\TicketPriorityMatrix;

final class UpdateHelpdeskTicketRequest extends FormRequest
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
        $levels = 'in:'.implode(',', TicketPriorityMatrix::Levels);

        return [
            'subject' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:20000'],
            'type' => ['sometimes', 'string', 'in:incident,request,problem,change,inquiry'],
            'impact' => ['sometimes', 'nullable', 'string', $levels],
            'urgency' => ['sometimes', 'nullable', 'string', $levels],
            'priority' => ['sometimes', 'string', 'in:low,medium,high,critical'],
            'category_id' => ['sometimes', 'nullable', 'uuid', $this->tenantExists('esm_service_categories')],
            'customer_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('contacts')],
            'service_contract_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_contracts')],
            'asset_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('eam_fixed_assets')],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:50'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'assigned_team_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_teams')],
            'assigned_technician_id' => ['sometimes', 'nullable', 'uuid', $this->tenantOwns('esm_service_technicians')],
        ];
    }
}
